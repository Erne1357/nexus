<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de la bandeja de un departamento: lo que le piden las carreras en una modalidad y un periodo
 * (reglas-de-negocio.md, "Propuesta de carrera"; M6: una propuesta por carrera y modalidad, con las filas de cada
 * campus). Quién la ve lo decide ProposalReviewPolicy::viewAny en el componente, y quién decide cada parte,
 * ProposalReviewPolicy::decide.
 */
final class DepartmentInboxQuery
{
    public function __construct(
        private readonly CatalogReviewQuery $catalogReviews,
        private readonly ProposalReviewRules $reviewRules,
    ) {}

    /**
     * Todo lo que muestra la bandeja (DepartmentInbox); una parte se decide si ProposalReviewRules::isDecidable lo
     * dice: pendiente, con la propuesta enviada o regresada por otro departamento (confirmado el 2 de noviembre de
     * 2026). Un número fijo de consultas, sin importar cuántas carreras, partes o filas haya: la carga del departamento
     * en la modalidad (M1); los catálogos sin validar en ella (CatalogReviewQuery::pendingFor, HC-14); las propuestas del
     * periodo y la modalidad (M6); las partes del departamento, cada una con su propuesta ya puesta (para
     * ProposalReviewPolicy::decide sin otra consulta); las filas con grupos de sus materias en esas partes, con sus
     * materias y sus campus; las carreras, y el tamaño objetivo de los grupos (SuggestedGroups).
     */
    public function inbox(Term $term, Modality $modality, Department $department): DepartmentInbox
    {
        $load = AcademicLoad::query()->ofDepartment($term->getKey(), $modality->getKey(), $department->getKey())->first();

        $proposals = CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->get()
            ->keyBy('id');

        $reviews = ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->whereIn('career_proposal_id', $proposals->keys()->all())
            ->get()
            ->keyBy('career_proposal_id');

        $correctionBy = $this->returnedByOthers($department, $reviews->keys()->all());
        $items = $this->requestedItems($department, $reviews->keys()->all());
        $careers = $this->careers($reviews->keys()->map(fn (string $id): string => $proposals->get($id)->career_id)->all());
        $proposalsByCareer = $proposals->keyBy('career_id');

        $anomalies = ProposalAnomalies::fromSettings();

        $rows = $careers
            ->map(function (Career $career) use ($proposalsByCareer, $reviews, $items, $anomalies, $correctionBy): array {
                $proposal = $proposalsByCareer->get($career->getKey());
                $review = $proposal === null || $proposal->status === ProposalStatus::Draft ? null : $reviews->get($proposal->getKey());
                $review?->setRelation('proposal', $proposal);
                $requested = $review === null ? collect() : $items->get($proposal->getKey(), collect());

                return [
                    'career' => $career,
                    'status' => InboxStatus::ofPart($review?->status),
                    'decidable' => $review !== null && $this->reviewRules->isDecidable($proposal->status, $review->status),
                    'correctionBy' => $review !== null && $proposal->status === ProposalStatus::Returned && $review->status === ProposalReviewStatus::Pending
                        ? $correctionBy->get($proposal->getKey(), [])
                        : [],
                    'proposal' => $proposal,
                    'review' => $review,
                    'subjects' => $requested->pluck('subject_id')->unique()->count(),
                    'groups' => (int) $requested->sum('requested_groups'),
                    'rows' => $requested->values()->all(),
                    'anomalies' => $requested->filter(fn (ProposalItem $item): bool => $anomalies->for($item->eligible_students, $item->expected_students, $item->requested_groups) !== [])->count(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => $a['status']->order() <=> $b['status']->order(),
                fn (array $a, array $b): int => $a['career']->code <=> $b['career']->code,
            ])
            ->values();

        return new DepartmentInbox(
            term: $term,
            department: $department,
            load: $load,
            pendingCatalogs: $this->catalogReviews->pendingFor($term, $modality, $department),
            modality: $modality,
            careers: $rows->all(),
            subjects: $this->parallels($rows),
            anomalies: $anomalies,
        );
    }

    /**
     * Los departamentos cuya bandeja puede ver el usuario en la modalidad (los que cubre con alguno de los roles que
     * ven la carga), por clave: el del jefe, o todos para subdirección y admin. Con `$term`, también el que se
     * desactivó o se borró pero tiene partes en ese periodo y modalidad, para que lo pendiente se pueda decidir.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context, Modality $modality, ?Term $term = null): Collection
    {
        return Department::query()
            ->withTrashed()
            ->where(fn ($query) => $query
                ->where(fn ($current) => $current->whereNull('deleted_at')->where('is_active', true))
                ->when($term !== null, fn ($query) => $query->orWhereIn('id', ProposalReview::query()
                    ->select('department_id')
                    ->whereIn('career_proposal_id', CareerProposal::query()->select('id')->where('term_id', $term->getKey())->where('modality_id', $modality->getKey()))))
            )
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $context->allows(AcademicLoadPermission::ProposalReviewView, Target::of(modality: $modality->getKey(), unit: $department->getKey())))
            ->values();
    }

    /**
     * Las carreras con una parte del departamento que se puede decidir (ProposalReviewRules::isDecidable) en el
     * periodo y la modalidad, por clave de carrera, de `id => clave`; una sola consulta, sin armar la bandeja. Para
     * saber a cuál pasar después de decidir una.
     *
     * @return array<string, string>
     */
    public function decidableCareers(string $termId, string $modalityId, Department $department): array
    {
        return ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->whereHas('proposal', fn ($proposal) => $proposal->where('term_id', $termId)->where('modality_id', $modalityId))
            ->with(['proposal.career' => fn ($career) => $career->withTrashed()])
            ->get()
            ->filter(fn (ProposalReview $review): bool => $this->reviewRules->isDecidable($review->proposal->status, $review->status))
            ->mapWithKeys(fn (ProposalReview $review): array => [$review->proposal->career->getKey() => $review->proposal->career->code])
            ->sort()
            ->all();
    }

    /**
     * La bandeja con los ids que la página guardó al entrar (periodo, modalidad y departamento, ya autorizados).
     *
     * @throws ModelNotFoundException<Term>
     */
    public function inboxOf(string $termId, string $modalityId, string $departmentId): DepartmentInbox
    {
        return $this->inbox(
            KeyLookup::findOrFail(Term::query(), $termId),
            KeyLookup::findOrFail(Modality::withTrashed(), $modalityId),
            KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId),
        );
    }

    /**
     * La modalidad y el departamento de la bandeja, por los ids que guardó la página, sin armarla: lo que pide
     * ProposalReviewPolicy::viewAny antes de decidir una parte.
     *
     * @return array{0: Modality, 1: Department}
     */
    public function scopeOf(string $modalityId, string $departmentId): array
    {
        return [
            KeyLookup::findOrFail(Modality::withTrashed(), $modalityId),
            KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId),
        ];
    }

    /**
     * La parte de un departamento por un id que llega del cliente, con su propuesta y la carrera de esta; la de
     * otro departamento no existe. Con `$termId` y `$modalityId`, tampoco la de otro periodo u otra modalidad.
     *
     * @throws ModelNotFoundException<ProposalReview>
     */
    public function reviewOrFail(Department $department, string $id, ?string $termId = null, ?string $modalityId = null): ProposalReview
    {
        $query = ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->with(['proposal.career' => fn ($career) => $career->withTrashed()])
            ->when($termId !== null, fn ($query) => $query->whereHas('proposal', fn ($proposal) => $proposal->where('term_id', $termId)))
            ->when($modalityId !== null, fn ($query) => $query->whereHas('proposal', fn ($proposal) => $proposal->where('modality_id', $modalityId)));

        return KeyLookup::findOrFail($query, $id);
    }

    /**
     * Las claves de los otros departamentos que regresaron cada propuesta, por id de propuesta y por clave; una
     * sola consulta. Es lo que la bandeja muestra como «en corrección por».
     *
     * @param  list<string>  $proposalIds  las propuestas con parte de este departamento
     * @return Collection<string, list<string>>
     */
    private function returnedByOthers(Department $department, array $proposalIds): Collection
    {
        return ProposalReview::query()
            ->join('departments', 'departments.id', '=', 'proposal_reviews.department_id')
            ->whereIn('proposal_reviews.career_proposal_id', $proposalIds)
            ->where('proposal_reviews.department_id', '!=', $department->getKey())
            ->where('proposal_reviews.status', ProposalReviewStatus::Returned)
            ->whereNull('departments.deleted_at')
            ->orderBy('departments.code')
            ->toBase()
            ->get(['proposal_reviews.career_proposal_id', 'departments.code'])
            ->groupBy('career_proposal_id')
            ->map(fn (Collection $rows): array => $rows->pluck('code')->unique()->values()->all());
    }

    /**
     * Las filas con grupos de las materias del departamento en las propuestas dadas, con su materia (también si se
     * borró después) y su campus, por propuesta y, dentro, por semestre, clave de materia y clave de campus.
     *
     * @param  list<string>  $proposalIds
     * @return Collection<string, Collection<int, ProposalItem>>
     */
    private function requestedItems(Department $department, array $proposalIds): Collection
    {
        return ProposalItem::query()
            ->requested()
            ->whereIn('proposal_items.career_proposal_id', $proposalIds)
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->leftJoin('campuses', 'campuses.id', '=', 'proposal_items.campus_id')
            ->where('subjects.department_id', $department->getKey())
            ->orderBy('proposal_items.semester')
            ->orderBy('subjects.code')
            ->orderBy('campuses.code')
            ->select('proposal_items.*')
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'campus' => fn ($query) => $query->withTrashed()])
            ->get()
            ->groupBy('career_proposal_id');
    }

    /**
     * Las carreras ofrecidas en algún campus activo (`career_campus` activa y sin borrar, carrera sin borrar; las que
     * tienen columnas en una propuesta, MS5) y las que tienen una parte del departamento aunque ya no se ofrezcan o se
     * hayan borrado (supuesto por confirmar), por clave.
     *
     * @param  list<string>  $withPart  ids de las carreras con una parte del departamento
     * @return Collection<int, Career>
     */
    private function careers(array $withPart): Collection
    {
        $offered = CareerCampus::query()
            ->select('career_id')
            ->whereIn('campus_id', Campus::query()->select('id')->where('is_active', true))
            ->where('is_active', true);

        return Career::query()
            ->withTrashed()
            ->where(fn ($query) => $query
                ->where(fn ($current) => $current->whereNull('deleted_at')->whereIn('id', $offered))
                ->orWhereIn('id', $withPart))
            ->orderBy('code')
            ->get();
    }

    /**
     * La sugerencia de paralelos de cada materia en cada campus con las partes que cuentan: las aceptadas y las que se
     * pueden decidir, que son las pendientes de una propuesta enviada o regresada por otro departamento (supuesto por
     * confirmar), con el tamaño objetivo de la materia. Es por materia y campus porque un grupo es de un campus (M8) y
     * no junta alumnos de C1 y C2 (supuesto por confirmar). Por clave de materia y de campus; las carreras, por clave.
     *
     * @param  Collection<int, array{career: Career, status: InboxStatus, decidable: bool, proposal: ?CareerProposal, review: ?ProposalReview, rows: list<ProposalItem>}>  $careers
     * @return list<array{subject: Subject, campus: ?Campus, expected: int, parallels: ParallelGroups}>
     */
    private function parallels(Collection $careers): array
    {
        $sizes = SuggestedGroups::fromSettings();

        return $careers
            ->filter(fn (array $row): bool => $row['status'] === InboxStatus::Accepted || $row['decidable'])
            ->sortBy(fn (array $row): string => $row['career']->code)
            ->flatMap(fn (array $row): array => array_map(fn (ProposalItem $item): array => ['career' => $row['career']->code, 'item' => $item], $row['rows']))
            ->groupBy(fn (array $entry): string => $entry['item']->subject_id.'|'.$entry['item']->campus_id)
            ->map(function (Collection $entries) use ($sizes): array {
                $subject = $entries->first()['item']->subject;
                $campus = $entries->first()['item']->campus;
                $demand = $entries->mapWithKeys(fn (array $entry): array => [$entry['career'] => [
                    'expected' => $entry['item']->expected_students,
                    'requested' => $entry['item']->requested_groups,
                ]])->all();

                return [
                    'subject' => $subject,
                    'campus' => $campus,
                    'expected' => (int) $entries->sum(fn (array $entry): int => $entry['item']->expected_students),
                    'parallels' => ParallelGroups::suggest($sizes->sizeFor($subject->needs_lab), $demand),
                ];
            })
            ->sortBy(fn (array $row): string => $row['subject']->code.'|'.($row['campus']?->code ?? ''))
            ->values()
            ->all();
    }
}
