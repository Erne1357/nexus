<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
     * materias; las partes y sus departamentos; y una por departamento involucrado para sus catálogos sin
     * validar (CatalogReviewQuery::pendingFor, HC-14).
     */
    public function overview(CareerProposal $proposal): ProposalOverview
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

        $requested = $items->filter(fn (ProposalItem $item): bool => $item->requested_groups > 0);

        $pendingCatalogs = $requested
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
                'groups' => (int) $requested->sum('requested_groups'),
                'expected' => (int) $requested->sum('expected_students'),
            ],
            pendingCatalogs: $pendingCatalogs,
        );
    }
}
