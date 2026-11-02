<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Policies\AcademicLoadPolicy;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de la bandeja de un departamento: lo que le piden las carreras de un campus en un periodo
 * (reglas-de-negocio.md, "Propuesta de carrera"). Quién la ve lo decide ProposalReviewPolicy::viewAny en el
 * componente, y quién decide cada parte, ProposalReviewPolicy::decide.
 */
final class DepartmentInboxQuery
{
    public function __construct(private readonly CatalogReviewQuery $catalogReviews) {}

    /**
     * Todo lo que muestra la bandeja (DepartmentInbox). Un número fijo de consultas, sin importar cuántas carreras,
     * partes o filas haya: la carga; los catálogos sin validar (CatalogReviewQuery::pendingFor, HC-14); las
     * propuestas del periodo y campus; las partes del departamento, cada una con su propuesta ya puesta (para
     * ProposalReviewPolicy::decide sin otra consulta); las filas con grupos de sus materias en esas partes, con sus
     * materias; las carreras, y el tamaño objetivo de los grupos (SuggestedGroups).
     */
    public function inbox(Term $term, Campus $campus, Department $department): DepartmentInbox
    {
        $load = AcademicLoad::query()
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey())
            ->where('department_id', $department->getKey())
            ->first();

        $proposals = CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey())
            ->get()
            ->keyBy('id');

        $reviews = ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->whereIn('career_proposal_id', $proposals->keys()->all())
            ->get()
            ->keyBy('career_proposal_id');

        $items = $this->requestedItems($department, $reviews->keys()->all());
        $careers = $this->careers($campus, $reviews->keys()->map(fn (string $id): string => $proposals->get($id)->career_id)->all());
        $proposalsByCareer = $proposals->keyBy('career_id');

        $rows = $careers
            ->map(function (Career $career) use ($proposalsByCareer, $reviews, $items): array {
                $proposal = $proposalsByCareer->get($career->getKey());
                $review = $proposal === null || $proposal->status === ProposalStatus::Draft ? null : $reviews->get($proposal->getKey());
                $review?->setRelation('proposal', $proposal);
                $requested = $review === null ? collect() : $items->get($proposal->getKey(), collect());

                return [
                    'career' => $career,
                    'status' => InboxStatus::ofPart($review?->status),
                    'decidable' => $review?->status === ProposalReviewStatus::Pending && $proposal->status === ProposalStatus::Submitted,
                    'proposal' => $proposal,
                    'review' => $review,
                    'subjects' => $requested->count(),
                    'groups' => (int) $requested->sum('requested_groups'),
                    'expected' => (int) $requested->sum('expected_students'),
                    'rows' => $requested->values()->all(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => $a['status']->order() <=> $b['status']->order(),
                fn (array $a, array $b): int => $a['career']->code <=> $b['career']->code,
            ])
            ->values();

        return new DepartmentInbox(
            term: $term,
            campus: $campus,
            department: $department,
            load: $load,
            pendingCatalogs: $this->catalogReviews->pendingFor($term, $campus, $department),
            careers: $rows->all(),
            subjects: $this->parallels($rows),
        );
    }

    /**
     * Los departamentos cuya bandeja puede ver el usuario en el campus (los que cubre con alguno de los roles que
     * ven la carga), por clave: el del jefe, o todos para subdirección y admin. Con `$term`, también el que se
     * desactivó o se borró pero tiene partes en ese periodo y campus, para que lo pendiente se pueda decidir.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(ScopedContext $context, Campus $campus, ?Term $term = null): Collection
    {
        return Department::query()
            ->withTrashed()
            ->where(fn ($query) => $query
                ->where(fn ($current) => $current->whereNull('deleted_at')->where('is_active', true))
                ->when($term !== null, fn ($query) => $query->orWhereIn('id', ProposalReview::query()
                    ->select('department_id')
                    ->whereIn('career_proposal_id', CareerProposal::query()->select('id')->where('term_id', $term->getKey())->where('campus_id', $campus->getKey()))))
            )
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $context->covers(AcademicLoadPolicy::VIEWERS, $campus, department: $department->getKey()))
            ->values();
    }

    /**
     * La bandeja con los ids que la página guardó al entrar (periodo, campus y departamento, ya autorizados).
     *
     * @throws ModelNotFoundException<Term>
     */
    public function inboxOf(string $termId, string $campusId, string $departmentId): DepartmentInbox
    {
        return $this->inbox(
            KeyLookup::findOrFail(Term::query(), $termId),
            KeyLookup::findOrFail(Campus::query(), $campusId),
            KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId),
        );
    }

    /**
     * El campus y el departamento de la bandeja, por los ids que guardó la página, sin armarla: lo que pide
     * ProposalReviewPolicy::viewAny antes de decidir una parte.
     *
     * @return array{0: Campus, 1: Department}
     */
    public function scopeOf(string $campusId, string $departmentId): array
    {
        return [
            KeyLookup::findOrFail(Campus::query(), $campusId),
            KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId),
        ];
    }

    /**
     * La parte de un departamento por un id que llega del cliente, con su propuesta y la carrera de esta; la de
     * otro departamento no existe. Con `$termId` y `$campusId`, tampoco la de otro periodo u otro campus.
     *
     * @throws ModelNotFoundException<ProposalReview>
     */
    public function reviewOrFail(Department $department, string $id, ?string $termId = null, ?string $campusId = null): ProposalReview
    {
        $query = ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->with(['proposal.career' => fn ($career) => $career->withTrashed()])
            ->when($termId !== null, fn ($query) => $query->whereHas('proposal', fn ($proposal) => $proposal->where('term_id', $termId)))
            ->when($campusId !== null, fn ($query) => $query->whereHas('proposal', fn ($proposal) => $proposal->where('campus_id', $campusId)));

        return KeyLookup::findOrFail($query, $id);
    }

    /**
     * Las filas con grupos de las materias del departamento en las propuestas dadas, con su materia (también si se
     * borró después), por propuesta y, dentro, por semestre y clave de materia.
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
            ->where('subjects.department_id', $department->getKey())
            ->orderBy('proposal_items.semester')
            ->orderBy('subjects.code')
            ->select('proposal_items.*')
            ->with(['subject' => fn ($query) => $query->withTrashed()])
            ->get()
            ->groupBy('career_proposal_id');
    }

    /**
     * Las carreras ofrecidas en el campus (`career_campus` activa y sin borrar, carrera sin borrar) y las que tienen
     * una parte del departamento aunque ya no se ofrezcan o se hayan borrado (supuesto por confirmar), por clave.
     *
     * @param  list<string>  $withPart  ids de las carreras con una parte del departamento
     * @return Collection<int, Career>
     */
    private function careers(Campus $campus, array $withPart): Collection
    {
        $offered = CareerCampus::query()
            ->select('career_id')
            ->where('campus_id', $campus->getKey())
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
     * La sugerencia de paralelos de cada materia con las partes que cuentan: las aceptadas y las pendientes de una
     * propuesta enviada (supuesto por confirmar), con el tamaño objetivo de la materia. Por clave de materia; las
     * carreras, por clave.
     *
     * @param  Collection<int, array{career: Career, status: InboxStatus, decidable: bool, proposal: ?CareerProposal, review: ?ProposalReview, rows: list<ProposalItem>}>  $careers
     * @return list<array{subject: Subject, expected: int, parallels: ParallelGroups}>
     */
    private function parallels(Collection $careers): array
    {
        $sizes = SuggestedGroups::fromSettings();

        return $careers
            ->filter(fn (array $row): bool => $row['status'] === InboxStatus::Accepted || $row['decidable'])
            ->sortBy(fn (array $row): string => $row['career']->code)
            ->flatMap(fn (array $row): array => array_map(fn (ProposalItem $item): array => ['career' => $row['career']->code, 'item' => $item], $row['rows']))
            ->groupBy(fn (array $entry): string => $entry['item']->subject_id)
            ->map(function (Collection $entries) use ($sizes): array {
                $subject = $entries->first()['item']->subject;
                $demand = $entries->mapWithKeys(fn (array $entry): array => [$entry['career'] => [
                    'expected' => $entry['item']->expected_students,
                    'requested' => $entry['item']->requested_groups,
                ]])->all();

                return [
                    'subject' => $subject,
                    'expected' => (int) $entries->sum(fn (array $entry): int => $entry['item']->expected_students),
                    'parallels' => ParallelGroups::suggest($sizes->sizeFor($subject->needs_lab), $demand),
                ];
            })
            ->sortBy(fn (array $row): string => $row['subject']->code)
            ->values()
            ->all();
    }
}
