<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lecturas de las revisiones de catálogo.
 */
final class CatalogReviewQuery
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * Los catálogos que todavía bloquean la salida de borrador de la carga de un departamento en un campus
     * (HC-14, como la reescribe D2): cada catálogo general cuya revisión del periodo y el departamento (sin
     * campus) falta o no está validada, y los salones si el perfil del campus usa salones y la revisión del
     * periodo, el campus y el departamento falta o no está validada. En el orden del enum Catalog.
     *
     * Con `$lock` las revisiones que lee quedan con bloqueo compartido (FOR SHARE) hasta el fin de la
     * transacción: quien valida HC-14 dentro de una transacción (CatalogsValidated, desde TransitionLoad)
     * impide que otra sesión des-valide un catálogo entre la lectura y el cambio de estado. Fuera de una
     * transacción no tiene efecto.
     *
     * @return list<Catalog>
     */
    public function pendingFor(Term|string $term, Campus $campus, Department|string $department, bool $lock = false): array
    {
        $termId = $term instanceof Term ? $term->getKey() : $term;
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        $required = array_values(array_filter(
            Catalog::cases(),
            fn (Catalog $catalog): bool => $catalog->isGeneral() || $campus->profile->usesRooms,
        ));

        $validated = CatalogReview::query()
            ->where('term_id', $termId)
            ->where('department_id', $departmentId)
            ->where('status', ReviewStatus::Validated)
            ->whereIn('catalog', $required)
            ->where(fn ($query) => $query
                ->where(fn ($general) => $general->whereNull('campus_id')->whereIn('catalog', Catalog::general()))
                ->orWhere(fn ($rooms) => $rooms->where('campus_id', $campus->getKey())->where('catalog', Catalog::Rooms)))
            ->when($lock, fn ($query) => $query->sharedLock())
            ->pluck('catalog')
            ->all();

        return array_values(array_filter($required, fn (Catalog $catalog): bool => ! in_array($catalog, $validated, true)));
    }

    /**
     * Las revisiones que ve un departamento en un periodo y un campus: las cuatro generales del periodo y la de
     * salones de ese campus, si existe (D2), en el orden del enum Catalog.
     *
     * @return Collection<int, CatalogReview>
     */
    public function forDepartment(Term|string $term, Campus|string $campus, Department|string $department): Collection
    {
        $order = array_flip(array_map(fn (Catalog $catalog): string => $catalog->value, Catalog::cases()));

        return CatalogReview::query()
            ->where('term_id', $term instanceof Term ? $term->getKey() : $term)
            ->where('department_id', $department instanceof Department ? $department->getKey() : $department)
            ->where(fn (Builder $query) => $query
                ->whereNull('campus_id')
                ->orWhere('campus_id', $campus instanceof Campus ? $campus->getKey() : $campus))
            ->get()
            ->sortBy(fn (CatalogReview $review): int => $order[$review->catalog->value])
            ->values();
    }

    /**
     * La última revisión validada, con foto, del mismo departamento, catálogo y campus en un periodo que empieza
     * antes que el de `$review` (CV3, confirmado el 2 de noviembre de 2026): contra su foto se cuentan las altas y las
     * bajas. Una reabierta no cuenta aunque conserve su foto (CV-S6, supuesto por confirmar). Nula en el primer periodo.
     */
    public function previousValidation(CatalogReview $review): ?CatalogReview
    {
        $review->loadMissing('term');

        return CatalogReview::query()
            ->select('catalog_reviews.*')
            ->join('terms', 'terms.id', '=', 'catalog_reviews.term_id')
            ->where('catalog_reviews.department_id', $review->department_id)
            ->where('catalog_reviews.catalog', $review->catalog)
            ->when(
                $review->campus_id === null,
                fn (Builder $query) => $query->whereNull('catalog_reviews.campus_id'),
                fn (Builder $query) => $query->where('catalog_reviews.campus_id', $review->campus_id),
            )
            ->where('catalog_reviews.status', ReviewStatus::Validated)
            ->whereNotNull('catalog_reviews.snapshot')
            ->where('terms.starts_on', '<', $review->term->starts_on->toDateString())
            ->orderByDesc('terms.starts_on')
            ->first();
    }

    /**
     * Lo que muestra el bloque de una revisión: cuántos registros activos e inactivos tiene su catálogo, las altas
     * (activos que no estaban en la foto anterior) y las bajas (de la foto anterior que ya no están activos, porque
     * se desactivaron, se borraron o dejaron el departamento), con la clave y el nombre de la foto; `null` las dos
     * si no hay foto anterior. Y cuántas observaciones siguen abiertas.
     *
     * @return array{active: int, inactive: int, added: ?list<array{id: string, code: string, name: ?string}>, removed: ?list<array{id: string, code: string, name: ?string}>, open_flags: int}
     */
    public function summary(CatalogReview $review): array
    {
        $records = $this->contents->records($review);
        $active = array_values(array_filter($records, fn (array $record): bool => $record['is_active']));
        $previous = $this->previousValidation($review)?->snapshot;
        $added = $removed = null;

        if ($previous !== null) {
            $previousIds = array_column($previous, 'id');
            $activeIds = array_column($active, 'id');

            $added = array_values(array_map(
                fn (array $record): array => ['id' => $record['id'], 'code' => $record['code'], 'name' => $record['name']],
                array_filter($active, fn (array $record): bool => ! in_array($record['id'], $previousIds, true)),
            ));
            $removed = array_values(array_filter($previous, fn (array $record): bool => ! in_array($record['id'], $activeIds, true)));
        }

        return [
            'active' => count($active),
            'inactive' => count($records) - count($active),
            'added' => $added,
            'removed' => $removed,
            'open_flags' => $review->flags()->whereNull('resolved_at')->count(),
        ];
    }
}
