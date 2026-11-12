<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlanSubject;
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
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de la propuesta de carrera para su página. Quién la ve lo decide CareerProposalPolicy en el
 * componente.
 */
final class CareerProposalQuery
{
    public function __construct(private readonly CatalogReviewQuery $catalogReviews) {}

    /**
     * La propuesta de una carrera en un campus y un periodo, o null si todavía no se arma.
     */
    public function findFor(Term $term, Campus $campus, Career $career): ?CareerProposal
    {
        return CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey())
            ->where('career_id', $career->getKey())
            ->first();
    }

    /**
     * Si el periodo está abierto en la modalidad del campus (M4; por el puente `campuses.modality_id` mientras la
     * propuesta sea por campus): sin eso no hay propuestas (StartCareerProposal).
     */
    public function termIsOpen(Term $term, Campus $campus): bool
    {
        return TermOpening::query()->where('term_id', $term->getKey())->where('modality_id', $campus->modality_id)->exists();
    }

    /**
     * Las carreras ofrecidas en el campus, por clave: las que tienen oferta `career_campus` activa y no están
     * borradas. No se mira `careers.is_active` a propósito: una carrera inactiva puede tener todavía alumnos, y
     * lo que decide es su oferta en el campus. De ellas, las de las que el usuario puede ver la
     * propuesta: el coordinador, las de sus alcances; la subdirección y el admin, todas (CareerProposalPolicy).
     *
     * @return Collection<int, Career>
     */
    public function careersFor(Actor $context, Campus $campus): Collection
    {
        return Career::query()
            ->whereIn('id', CareerCampus::query()->where('campus_id', $campus->getKey())->where('is_active', true)->select('career_id'))
            ->orderBy('code')
            ->get()
            ->filter(fn (Career $career): bool => $context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: $campus->modality_id, career: $career->getKey())))
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
     * Todo lo que muestra la página (ProposalOverview). Un número fijo de consultas, sin importar cuántas filas
     * tenga: la propuesta con su periodo, campus y carrera; las filas, sus materias y los departamentos de las
     * materias; las partes y sus departamentos; las especialidades de las materias en su plan; la seriación entre las
     * materias de la propuesta, la del plan de cada fila; y una por departamento involucrado para sus catálogos sin
     * validar (CatalogReviewQuery::pendingFor, HC-14). Esa última solo se arma con `$withPendingCatalogs`, que
     * es lo que pide el diálogo de enviar: sin él, `pendingCatalogs` queda vacío.
     */
    public function overview(CareerProposal $proposal, bool $withPendingCatalogs = true): ProposalOverview
    {
        $proposal->loadMissing([
            'term',
            'campus' => fn ($query) => $query->withTrashed(),
            'career' => fn ($query) => $query->withTrashed(),
        ]);

        $items = ProposalItem::query()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->orderBy('proposal_items.semester')
            ->orderBy('subjects.code')
            ->select('proposal_items.*')
            ->with(['subject' => fn ($query) => $query->withTrashed()->with(['department' => fn ($department) => $department->withTrashed()])])
            ->get();

        $reviews = ProposalReview::query()
            ->where('proposal_reviews.career_proposal_id', $proposal->getKey())
            ->join('departments', 'departments.id', '=', 'proposal_reviews.department_id')
            ->orderBy('departments.code')
            ->select('proposal_reviews.*')
            ->with(['department' => fn ($query) => $query->withTrashed()])
            ->get();

        $placements = StudyPlanSubject::withTrashed()
            ->whereIn('study_plan_id', $items->pluck('study_plan_id')->unique()->all())
            ->whereIn('subject_id', $items->pluck('subject_id')->all())
            ->whereNotNull('specialty_id')
            ->get(['study_plan_id', 'subject_id', 'specialty_id'])
            ->mapWithKeys(fn (StudyPlanSubject $placement): array => ["{$placement->study_plan_id}|{$placement->subject_id}" => $placement->specialty_id]);
        $specialtyOf = $items
            ->filter(fn (ProposalItem $item): bool => $placements->has("{$item->study_plan_id}|{$item->subject_id}"))
            ->mapWithKeys(fn (ProposalItem $item): array => [$item->subject_id => $placements["{$item->study_plan_id}|{$item->subject_id}"]])
            ->all();
        $inProposal = array_count_values(array_values($specialtyOf));
        $specialties = Specialty::withTrashed()->whereIn('id', array_keys($inProposal))->orderBy('code')->get(['id', 'name'])
            ->map(fn (Specialty $specialty): array => ['id' => $specialty->id, 'name' => $specialty->name, 'subjects' => $inProposal[$specialty->id]])
            ->all();

        $planOf = $items->pluck('study_plan_id', 'subject_id')->all();
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
                'catalogs' => $this->catalogReviews->pendingFor($proposal->term_id, $proposal->campus, $department),
            ])
            ->filter(fn (array $row): bool => $row['catalogs'] !== [])
            ->values()
            ->all();

        return new ProposalOverview(
            proposal: $proposal,
            semesters: $items->groupBy('semester')->map(fn ($rows): array => $rows->values()->all())->all(),
            reviews: $reviews->values()->all(),
            totals: [
                'subjects' => $requested->count(),
                'subjects_total' => $items->count(),
                'groups' => (int) $requested->sum('requested_groups'),
                'eligible_students' => $proposal->eligible_students,
            ],
            pendingCatalogs: $pendingCatalogs,
            anomalies: ProposalAnomalies::fromSettings(),
            specialtyOf: $specialtyOf,
            specialties: $specialties,
            seriation: $seriation,
        );
    }
}
