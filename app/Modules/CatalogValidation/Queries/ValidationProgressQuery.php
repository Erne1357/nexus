<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\RecipientsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo que lee el tablero de avance de validación (tarea 2.5): la matriz de departamentos por catálogo de un periodo.
 *
 * El total de un departamento son los catálogos que el admin abrió en el periodo (CV9; la de salones es una para
 * todos los campus, decisión 6, confirmada el 12 de noviembre de 2026). Una revisión que falta de un catálogo abierto, por
 * ejemplo la de un departamento nuevo, cuenta como pendiente. Todo sale de pocas consultas
 * para todos los departamentos, sin una por fila.
 */
final class ValidationProgressQuery
{
    public function __construct(
        private readonly RecipientsQuery $recipients,
        private readonly CatalogContentsQuery $contents,
        private readonly CatalogReviewQuery $catalogReviews,
    ) {}

    /**
     * Los campus donde el periodo está abierto, activos y sin borrar, por código: los de las modalidades donde se abrió
     * (M4; por el puente `campuses.modality_id` mientras el tablero sea por campus).
     *
     * @return Collection<int, Campus>
     */
    public function openCampuses(Term $term): Collection
    {
        return Campus::query()
            ->where('is_active', true)
            ->whereIn('modality_id', TermOpening::query()->where('term_id', $term->getKey())->select('modality_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * La matriz del periodo para los campus dados (los abiertos, o el que filtra la pantalla).
     *
     * `columns`: los catálogos abiertos en el periodo, con Salones al final (ninguno si no se abrió la validación). `rows`: cada
     * departamento activo, con sus revisiones por columna (nula si falta), cuántas lleva validadas del total, sus
     * pendientes y en revisión, cuántos registros lleva revisados de cuántos en cada una (`reviewed`, nulo si falta; CV7),
     * las observaciones abiertas, el jefe vigente, el estado de su carga en cada modalidad de esos campus (por el puente
     * `campuses.modality_id`; la carga es por departamento y modalidad, M1; evento LoadStatusesRequested) y el último recordatorio en los campus del alcance. El jefe sale de lo mismo que
     * recibe el aviso (RecipientsQuery::holders: permiso de validar la revisión sobre el departamento, usuario activo), `heads` y `head` (el primero), y de
     * ahí `remind_blocked_reason`, el motivo por el que «Recordar» no aplica (nada pendiente o sin jefe), nulo si
     * aplica; `remindable` cuenta los departamentos visibles a los que sí y sus jefes distintos. `totals`: por columna. `global` y `flags` cuentan
     * todos los departamentos; `search` (departamento o jefe) y `status` (`pending`: con algo sin validar;
     * `validated`: todo validado) solo filtran `rows` y `totals`.
     *
     * @param  Collection<int, Campus>  $campuses
     *                                             Con `$departmentId`, solo ese departamento (para el drawer de uno que los filtros dejaron fuera).
     * @return array{
     *     columns: list<array{key: string, catalog: Catalog}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, array{validated: int, in_review: int, total: int}>,
     *     global: array{validated: int, in_review: int, pending: int, total: int, percent: int},
     *     flags: array{open: int, departments: int, resolved: int},
     *     remindable: array{departments: int, heads: int}
     * }
     */
    public function board(Term $term, Collection $campuses, string $search = '', string $status = '', ?string $departmentId = null): array
    {
        $catalogs = $this->catalogReviews->openedCatalogs($term);
        $ordered = [...array_filter($catalogs, fn (Catalog $catalog): bool => $catalog !== Catalog::Rooms), ...array_filter($catalogs, fn (Catalog $catalog): bool => $catalog === Catalog::Rooms)];
        $columns = array_map(fn (Catalog $catalog): array => ['key' => $catalog->value, 'catalog' => $catalog], $ordered);

        $departments = Department::query()->where('is_active', true)->when($departmentId !== null, fn ($query) => $query->whereKey($departmentId))->orderBy('code')->get();

        $reviews = CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->whereIn('department_id', $departments->modelKeys())
            ->whereIn('catalog', $catalogs)
            ->with('validator')
            ->get()
            ->keyBy(fn (CatalogReview $review): string => $review->department_id.'|'.$review->catalog->value);

        $reviewed = $this->contents->progressOf($reviews->values());

        $flags = CatalogReviewFlag::query()
            ->join('catalog_reviews', 'catalog_reviews.id', '=', 'catalog_review_flags.review_id')
            ->where('catalog_reviews.term_id', $term->getKey())
            ->whereIn('catalog_reviews.department_id', $departments->modelKeys())
            ->whereIn('catalog_reviews.catalog', $catalogs)
            ->selectRaw('catalog_reviews.department_id, count(*) filter (where catalog_review_flags.resolved_at is null) as open, count(*) filter (where catalog_review_flags.resolved_at is not null) as resolved')
            ->groupBy('catalog_reviews.department_id')
            ->get()
            ->keyBy('department_id');

        $campus = $campuses->first();
        $heads = $departments->mapWithKeys(fn (Department $department): array => [
            $department->getKey() => $campus === null ? collect() : collect($this->recipients->holders(CatalogValidationPermission::ReviewValidate, Target::of(campus: $campus->getKey(), unit: $department->getKey())))->pluck('user'),
        ]);

        $modalities = Modality::query()->whereIn('id', $campuses->pluck('modality_id')->filter()->unique()->values())->orderBy('code')->get();
        $statuses = new LoadStatusesRequested($term, $modalities->modelKeys());
        event($statuses);

        $reminders = $this->lastReminders($term, $departments->modelKeys(), $campuses->pluck('code')->all());

        $rows = $departments->map(function (Department $department) use ($columns, $reviews, $reviewed, $flags, $heads, $modalities, $statuses, $reminders): array {
            $cells = [];

            foreach ($columns as $column) {
                $cells[$column['key']] = $reviews->get($department->getKey().'|'.$column['key']);
            }

            $validated = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::Validated));
            $inReview = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::InReview));
            $total = count($columns);

            return [
                'department' => $department,
                'heads' => $heads->get($department->getKey()),
                'head' => $heads->get($department->getKey())->first(),
                'cells' => $cells,
                'reviewed' => array_map(fn (?CatalogReview $review): ?array => $review === null ? null : $reviewed[$review->getKey()], $cells),
                'validated' => $validated,
                'in_review' => $inReview,
                'pending' => $total - $validated - $inReview,
                'remind_blocked_reason' => match (true) {
                    $validated === $total => 'No le falta nada por validar.',
                    $heads->get($department->getKey())->isEmpty() => 'No tiene jefe vigente.',
                    default => null,
                },
                'pending_columns' => array_values(array_filter($columns, fn (array $column): bool => $cells[$column['key']]?->status !== ReviewStatus::Validated)),
                'total' => $total,
                'open_flags' => (int) ($flags->get($department->getKey())?->open ?? 0),
                'resolved_flags' => (int) ($flags->get($department->getKey())?->resolved ?? 0),
                'loads' => $modalities->map(fn (Modality $modality): array => ['modality' => $modality, 'load' => $statuses->for($department->getKey(), $modality->getKey())])
                    ->filter(fn (array $entry): bool => $entry['load'] !== null)->values()->all(),
                'last_reminder' => $reminders[$department->getKey()] ?? null,
            ];
        })->all();

        $global = [
            'validated' => array_sum(array_column($rows, 'validated')),
            'in_review' => array_sum(array_column($rows, 'in_review')),
            'pending' => array_sum(array_column($rows, 'pending')),
            'total' => array_sum(array_column($rows, 'total')),
        ];
        $global['percent'] = $global['total'] === 0 ? 0 : (int) round($global['validated'] / $global['total'] * 100);

        $summary = [
            'open' => array_sum(array_column($rows, 'open_flags')),
            'departments' => count(array_filter($rows, fn (array $row): bool => $row['open_flags'] > 0)),
            'resolved' => array_sum(array_column($rows, 'resolved_flags')),
        ];

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $search, $status)));

        $remindable = array_filter($rows, fn (array $row): bool => $row['remind_blocked_reason'] === null);
        $remindableHeads = ['departments' => count($remindable), 'heads' => collect($remindable)->flatMap(fn (array $row) => $row['heads']->pluck('id'))->unique()->count()];

        $totals = [];

        foreach ($columns as $column) {
            $cells = array_column(array_column($rows, 'cells'), $column['key']);
            $validated = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::Validated));

            $totals[$column['key']] = [
                'validated' => $validated,
                'in_review' => count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::InReview)),
                'total' => count($rows),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'global' => $global, 'flags' => $summary, 'remindable' => $remindableHeads];
    }

    /**
     * Las observaciones abiertas de un departamento en el periodo, de las más recientes a las más antiguas, con el
     * registro observado (aunque se haya borrado), quién la puso y su revisión. Las revisiones no son de un campus
     * (decisión 6): entran las de los catálogos abiertos.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return Collection<int, CatalogReviewFlag>
     */
    public function openFlags(Term $term, Department $department, Collection $campuses): Collection
    {
        return CatalogReviewFlag::query()
            ->whereNull('resolved_at')
            ->whereHas('review', fn ($query) => $query
                ->where('term_id', $term->getKey())
                ->where('department_id', $department->getKey())
                ->whereIn('catalog', $this->catalogReviews->openedCatalogs($term)))
            ->with(['review.campus', 'review.department', 'flaggable', 'flagger'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Una observación por su id, que llega del navegador, con su revisión.
     */
    public function findFlag(string $id): CatalogReviewFlag
    {
        return KeyLookup::findOrFail(CatalogReviewFlag::query()->with('review.department', 'review.campus'), $id);
    }

    /**
     * Cuándo se recordó por última vez a cada departamento el periodo en alguno de los campus dados (códigos), según
     * la bitácora de RemindPendingReviews (`properties.campuses`).
     *
     * @param  list<string>  $departmentIds
     * @param  list<string>  $campusCodes
     * @return array<string, CarbonImmutable>
     */
    public function lastReminders(Term $term, array $departmentIds, array $campusCodes): array
    {
        if ($campusCodes === []) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new Department)->getMorphClass())
            ->whereIn('subject_id', $departmentIds)
            ->where('event', 'reminded')
            ->where('properties->term_id', $term->getKey())
            ->whereRaw("jsonb_exists_any((properties::jsonb)->'campuses', ?::text[])", ['{'.implode(',', array_map(fn (string $code): string => '"'.addcslashes($code, '"\\').'"', $campusCodes)).'}'])
            ->selectRaw('subject_id, max(created_at) as last')
            ->groupBy('subject_id')
            ->pluck('last', 'subject_id')
            ->map(fn (string $last): CarbonImmutable => CarbonImmutable::parse($last))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, string $search, string $status): bool
    {
        if ($status === 'pending' && $row['validated'] === $row['total']) {
            return false;
        }

        if ($status === 'validated' && $row['validated'] !== $row['total']) {
            return false;
        }

        $needle = Str::lower(Str::ascii(trim($search)));

        if ($needle === '') {
            return true;
        }

        $haystack = Str::lower(Str::ascii($row['department']->code.' '.$row['department']->name.' '.($row['heads']->pluck('name')->implode(' '))));

        return str_contains($haystack, $needle);
    }
}
