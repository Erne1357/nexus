<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas del catálogo de modalidades (ADR-0025) para las pantallas.
 */
final class ModalityQuery
{
    /**
     * La tabla del catálogo de Modalidades, por clave y sin las borradas. Al admin, todas, activas e inactivas; a
     * cualquier otro, las modalidades sobre las que tiene `core.modality.view` (Actor::constrain()), así ni el alcance
     * ni el permiso se repiten aquí. Busca `$search` en la clave o el nombre con TextSearch; `$active` filtra por estado
     * y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Modality>
     */
    public function forTable(Actor $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Modality::query()
            ->tap(fn (Builder $query) => $context->constrain($query, CorePermission::ModalityView, ['modality' => 'modalities.id']))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Las modalidades activas y sin borrar, por clave: donde se abre el periodo y se fija una ventana propia (M4).
     *
     * @return Collection<int, Modality>
     */
    public function active(): Collection
    {
        return Modality::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * La modalidad elegida, sin las borradas.
     *
     * @throws ModelNotFoundException<Modality>
     */
    public function findOrFail(string $id): Modality
    {
        return KeyLookup::findOrFail(Modality::query(), $id);
    }
}
