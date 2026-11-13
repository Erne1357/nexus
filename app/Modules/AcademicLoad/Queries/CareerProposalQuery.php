<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de la propuesta de carrera para su página: una por carrera y modalidad, con una columna por campus (M6).
 * Quién la ve lo decide CareerProposalPolicy en el componente.
 */
final class CareerProposalQuery
{
    public function __construct(
        private readonly CatalogReviewQuery $catalogReviews,
        private readonly RecipientsQuery $recipients,
    ) {}

    /**
     * La propuesta de una carrera en una modalidad y un periodo, o null si todavía no se arma.
     */
    public function findFor(Term $term, Modality $modality, Career $career): ?CareerProposal
    {
        return CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->where('career_id', $career->getKey())
            ->first();
    }

    /**
     * Si el periodo está abierto en la modalidad (M4): sin eso no hay propuestas (StartCareerProposal).
     */
    public function termIsOpen(Term $term, Modality $modality): bool
    {
        return TermOpening::query()->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())->exists();
    }

    /**
     * Las carreras ofrecidas en algún campus activo, por clave: las que tienen oferta `career_campus` activa en un campus
     * activo y no están borradas (las columnas de su propuesta, MS5). No se mira `careers.is_active` a propósito: una
     * carrera inactiva puede tener todavía alumnos, y lo que decide es su oferta. De ellas, las de las que el usuario
     * puede ver la propuesta en la modalidad: el coordinador, las de sus alcances (con o sin campus, porque ve toda la
     * propuesta, M7); la subdirección y el admin, todas (CareerProposalPolicy).
     *
     * @return Collection<int, Career>
     */
    public function careersFor(Actor $context, Modality $modality): Collection
    {
        $offered = CareerCampus::query()
            ->select('career_id')
            ->where('is_active', true)
            ->whereIn('campus_id', Campus::query()->select('id')->where('is_active', true));

        return Career::query()
            ->whereIn('id', $offered)
            ->orderBy('code')
            ->get()
            ->filter(fn (Career $career): bool => $context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: $modality->getKey(), career: $career->getKey())))
            ->values();
    }

    /**
     * La propuesta por un id que llega del cliente.
     *
     * @throws ModelNotFoundException<CareerProposal>
     */
    public function findOrFail(string $id): CareerProposal
    {
        return KeyLookup::findOrFail(CareerProposal::query(), $id);
    }

    /**
     * Una fila de la propuesta por un id que llega del cliente; la de otra propuesta no existe.
     *
     * @throws ModelNotFoundException<ProposalItem>
     */
    public function itemOrFail(CareerProposal $proposal, string $id): ProposalItem
    {
        return KeyLookup::findOrFail(ProposalItem::query()->where('career_proposal_id', $proposal->getKey()), $id);
    }

    /**
     * Las filas de una materia de la propuesta, una por columna, por un id de materia que llega del cliente; con su
     * campus y su materia. Una materia que la propuesta no tiene no existe.
     *
     * @return Collection<int, ProposalItem>
     *
     * @throws ModelNotFoundException<ProposalItem>
     */
    public function subjectItemsOrFail(CareerProposal $proposal, string $subjectId): Collection
    {
        $items = ProposalItem::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->where('subject_id', KeyLookup::find(Subject::withTrashed(), $subjectId)?->getKey())
            ->with(['campus' => fn ($query) => $query->withTrashed(), 'subject' => fn ($query) => $query->withTrashed()])
            ->get()
            ->sortBy(fn (ProposalItem $item): string => $item->campus?->code ?? '')
            ->values();

        if ($items->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ProposalItem::class, [$subjectId]);
        }

        return $items;
    }

    /**
     * Todo lo que muestra la página (ProposalOverview). Un número fijo de consultas, sin importar cuántas filas
     * tenga: la propuesta con su periodo, modalidad, carrera y quién la envió; las filas, sus materias, sus campus y los
     * departamentos de las materias; las partes y sus departamentos; las especialidades de las materias en su plan; la
     * seriación entre las materias de la propuesta, la del plan de cada fila; quiénes la coordinan
     * (RecipientsQuery::holdersByKey, una lectura para todas las columnas); y una por departamento involucrado para sus
     * catálogos sin validar (CatalogReviewQuery::pendingFor, en la modalidad, HC-14). Esa última solo se arma con
     * `$withPendingCatalogs`, que es lo que pide el diálogo de enviar: sin él, `pendingCatalogs` queda vacío.
     */
    public function overview(CareerProposal $proposal, bool $withPendingCatalogs = true): ProposalOverview
    {
        $proposal->loadMissing([
            'term',
            'modality' => fn ($query) => $query->withTrashed(),
            'career' => fn ($query) => $query->withTrashed(),
            'submitter',
        ]);

        $items = ProposalItem::query()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->orderBy('proposal_items.semester')
            ->orderBy('subjects.code')
            ->select('proposal_items.*')
            ->with([
                'subject' => fn ($query) => $query->withTrashed()->with(['department' => fn ($department) => $department->withTrashed()]),
                'campus' => fn ($query) => $query->withTrashed(),
            ])
            ->get();

        $columns = $items->map(fn (ProposalItem $item): ?Campus => $item->campus)
            ->unique(fn (?Campus $campus): string => ProposalOverview::columnKey($campus))
            ->sortBy(fn (?Campus $campus): string => $campus?->code ?? '')
            ->values()
            ->all();

        $subjects = $items->groupBy('subject_id')->map(function (Collection $rows) use ($columns): ProposalSubject {
            $first = $rows->first();
            $byColumn = $rows->keyBy(fn (ProposalItem $item): string => ProposalOverview::columnKey($item->campus));
            $cells = [];

            foreach ($columns as $campus) {
                if ($byColumn->has(ProposalOverview::columnKey($campus))) {
                    $cells[ProposalOverview::columnKey($campus)] = $byColumn->get(ProposalOverview::columnKey($campus));
                }
            }

            return new ProposalSubject($first->subject, $first->semester, $first->study_plan_id, $cells);
        });

        $reviews = ProposalReview::query()
            ->where('proposal_reviews.career_proposal_id', $proposal->getKey())
            ->join('departments', 'departments.id', '=', 'proposal_reviews.department_id')
            ->orderBy('departments.code')
            ->select('proposal_reviews.*')
            ->with(['department' => fn ($query) => $query->withTrashed(), 'decider'])
            ->get();

        $placements = StudyPlanSubject::withTrashed()
            ->whereIn('study_plan_id', $items->pluck('study_plan_id')->unique()->all())
            ->whereIn('subject_id', $items->pluck('subject_id')->unique()->all())
            ->whereNotNull('specialty_id')
            ->get(['study_plan_id', 'subject_id', 'specialty_id'])
            ->mapWithKeys(fn (StudyPlanSubject $placement): array => ["{$placement->study_plan_id}|{$placement->subject_id}" => $placement->specialty_id]);
        $specialtyOf = $subjects
            ->filter(fn (ProposalSubject $subject): bool => $placements->has("{$subject->studyPlanId}|{$subject->id()}"))
            ->mapWithKeys(fn (ProposalSubject $subject): array => [$subject->id() => $placements["{$subject->studyPlanId}|{$subject->id()}"]])
            ->all();
        $inProposal = array_count_values(array_values($specialtyOf));
        $specialties = Specialty::withTrashed()->whereIn('id', array_keys($inProposal))->orderBy('code')->get(['id', 'name'])
            ->map(fn (Specialty $specialty): array => ['id' => $specialty->id, 'name' => $specialty->name, 'subjects' => $inProposal[$specialty->id]])
            ->all();

        $planOf = $subjects->mapWithKeys(fn (ProposalSubject $subject): array => [$subject->id() => $subject->studyPlanId])->all();
        $seriation = SubjectPrerequisite::query()
            ->whereIn('study_plan_id', array_values(array_unique($planOf)))
            ->whereIn('subject_id', array_keys($planOf))
            ->whereIn('prerequisite_subject_id', array_keys($planOf))
            ->where('kind', PrerequisiteKind::Seriacion)
            ->orderBy('prerequisite_subject_id')
            ->get(['study_plan_id', 'subject_id', 'prerequisite_subject_id'])
            ->filter(fn (SubjectPrerequisite $row): bool => $planOf[$row->subject_id] === $row->study_plan_id)
            ->groupBy('subject_id')
            ->map(fn ($rows): array => $rows->pluck('prerequisite_subject_id')->all())
            ->all();

        $requested = $items->filter(fn (ProposalItem $item): bool => $item->requested_groups > 0);

        $pendingCatalogs = ! $withPendingCatalogs ? [] : $requested
            ->map(fn (ProposalItem $item): Department => $item->subject->department)
            ->unique(fn (Department $department): string => $department->getKey())
            ->sortBy(fn (Department $department): string => $department->code)
            ->map(fn (Department $department): array => [
                'department' => $department,
                'catalogs' => $this->catalogReviews->pendingFor($proposal->term_id, $proposal->modality, $department),
            ])
            ->filter(fn (array $row): bool => $row['catalogs'] !== [])
            ->values()
            ->all();

        $groupsByCampus = [];
        $subjectsByCampus = [];
        $eligibleByCampus = [];
        $frozen = $proposal->eligible_students_by_campus ?? [];

        foreach ($columns as $campus) {
            $key = ProposalOverview::columnKey($campus);
            $inColumn = $requested->filter(fn (ProposalItem $item): bool => ProposalOverview::columnKey($item->campus) === $key);
            $groupsByCampus[$key] = (int) $inColumn->sum('requested_groups');
            $subjectsByCampus[$key] = $inColumn->count();
            $eligibleByCampus[$key] = (int) ($frozen[$campus?->getKey() ?? CareerProposal::WITHOUT_CAMPUS] ?? 0);
        }

        return new ProposalOverview(
            proposal: $proposal,
            columns: $columns,
            semesters: $subjects->values()->groupBy(fn (ProposalSubject $subject): int => $subject->semester)->map(fn ($rows): array => $rows->values()->all())->sortKeys()->all(),
            reviews: $reviews->values()->all(),
            totals: [
                'subjects' => $requested->pluck('subject_id')->unique()->count(),
                'subjects_total' => $subjects->count(),
                'subjects_by_campus' => $subjectsByCampus,
                'groups' => (int) $requested->sum('requested_groups'),
                'groups_by_campus' => $groupsByCampus,
                'eligible_students' => $proposal->eligible_students,
                'eligible_by_campus' => $eligibleByCampus,
            ],
            pendingCatalogs: $pendingCatalogs,
            anomalies: ProposalAnomalies::fromSettings(),
            specialtyOf: $specialtyOf,
            specialties: $specialties,
            seriation: $seriation,
            coordinators: $this->coordinators($proposal, $columns),
        );
    }

    /**
     * Quiénes coordinan la carrera en la modalidad, por nombre, cada uno con las columnas que cubre: el coordinador de
     * un campus, la suya; uno sin campus, todas (M5, M7). Una sola lectura de los candidatos para todas las columnas.
     *
     * @param  list<Campus|null>  $columns
     * @return list<array{user: User, campuses: list<string>}>
     */
    private function coordinators(CareerProposal $proposal, array $columns): array
    {
        $targets = [];

        foreach ($columns as $campus) {
            $targets[ProposalOverview::columnKey($campus)] = Target::of(modality: $proposal->modality_id, campus: $campus?->getKey(), career: $proposal->career_id);
        }

        $byUser = [];

        foreach ($this->recipients->holdersByKey(AcademicLoadPermission::ProposalUpdate, $targets) as $column => $holders) {
            foreach ($holders as $holder) {
                $byUser[$holder['user']->getKey()] ??= ['user' => $holder['user'], 'campuses' => []];
                $byUser[$holder['user']->getKey()]['campuses'][] = (string) $column;
            }
        }

        return array_values($byUser);
    }
}
