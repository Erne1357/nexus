<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la lista de un catálogo de la pantalla de validación: los registros de la revisión
 * (CatalogContentsQuery) paginados, con búsqueda, filtro de estado y de observación abierta, y las
 * observaciones de un registro. Quién ve la revisión lo decide CatalogReviewPolicy.
 */
final class CatalogRecordsQuery
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * Una página de registros por clave. `$flag` es `with` (con observación abierta), `without` (sin ella) o vacío;
     * `$onlyIds`, si no es null, deja solo esos registros (el filtro «Falta» de docentes).
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function page(CatalogReview $review, string $search = '', ?bool $active = null, string $flag = '', int $perPage = 15, ?array $onlyIds = null): LengthAwarePaginator
    {
        $query = $this->contents->query($review);
        $model = $query->getModel();

        match ($review->catalog) {
            Catalog::Rooms => $query->with('building'),
            Catalog::StudyPlans => $query->with('career'),
            default => null,
        };

        TextSearch::apply($query, [$review->catalog === Catalog::Teachers ? 'rfc' : 'code', 'name'], $search);

        if ($active !== null) {
            $query->where($model->qualifyColumn('is_active'), $active);
        }

        if ($flag === 'with' || $flag === 'without') {
            $open = $review->flags()->whereNull('resolved_at')->where('flaggable_type', $model->getMorphClass())->select('flaggable_id');
            $flag === 'with' ? $query->whereIn($model->qualifyColumn('id'), $open) : $query->whereNotIn($model->qualifyColumn('id'), $open);
        }

        if ($onlyIds !== null) {
            $query->whereIn($model->qualifyColumn('id'), $onlyIds);
        }

        return $query->paginate($perPage);
    }

    /**
     * Las observaciones abiertas de los registros dados, por id de registro.
     *
     * @param  Collection<int, string>|list<string>  $ids
     * @return array<string, list<ReviewFlag>>
     */
    public function openFlags(CatalogReview $review, Collection|array $ids): array
    {
        return $review->flags()
            ->whereNull('resolved_at')
            ->whereIn('flaggable_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('flaggable_id')
            ->map(fn (Collection $flags): array => $flags->pluck('flag')->all())
            ->all();
    }

    /**
     * Todas las observaciones de un registro: las abiertas primero, con quién las puso y las resolvió.
     *
     * @return Collection<int, CatalogReviewFlag>
     */
    public function flagsOf(CatalogReview $review, Model $record): Collection
    {
        return $review->flags()
            ->where('flaggable_type', $record->getMorphClass())
            ->where('flaggable_id', $record->getKey())
            ->with(['flagger', 'resolver'])
            ->orderByRaw('resolved_at is not null')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Una observación de la revisión por su id, que llega del navegador.
     *
     * @throws ModelNotFoundException
     */
    public function findFlag(CatalogReview $review, string $id): CatalogReviewFlag
    {
        return KeyLookup::findOrFail($review->flags()->getQuery(), $id);
    }
}
