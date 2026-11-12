<?php

namespace App\Modules\Facilities\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use App\Modules\Facilities\Models\Building;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de edificios para las pantallas. Son de un campus, el activo de la credencial, y los ve quien
 * lo cubre según BuildingPolicy: el admin y la Subdirección de ese campus; a cualquier otro, ninguno.
 */
final class BuildingQuery
{
    /**
     * La tabla de edificios del campus, por clave y sin los borrados, con cuántos salones sin borrar tiene
     * cada uno (`rooms_count`). Busca `$search` en la clave o el nombre con TextSearch; `$active` filtra por
     * estado y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Building>
     */
    public function forTable(Actor $context, Campus $campus, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Building::query()
            ->where('campus_id', $campus->getKey())
            ->tap(fn (Builder $query) => $context->constrain($query, FacilitiesPermission::BuildingView, ['campus' => 'buildings.campus_id']))
            ->withCount('rooms')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los edificios sin borrar del campus, por clave, como opciones de x-select: «A · Edificio A»; los
     * inactivos también, porque un salón puede seguir en uno, y lo dicen.
     *
     * @return list<array{id: string, name: string}>
     */
    public function options(Campus $campus): array
    {
        return Building::query()
            ->where('campus_id', $campus->getKey())
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Building $building): array => [
                'id' => $building->id,
                'name' => "{$building->code} · {$building->name}".($building->is_active ? '' : ' (inactivo)'),
            ])
            ->all();
    }

    /**
     * Los edificios sin borrar de los campus activos, por campus y clave, como opciones de x-select: «C2 · A · Edificio
     * A». Para mover un salón a un edificio de otro campus al validar los catálogos (CV2, CV-S10); los inactivos también,
     * y lo dicen.
     *
     * @return list<array{id: string, name: string}>
     */
    public function optionsInActiveCampuses(): array
    {
        return Building::query()
            ->join('campuses', 'campuses.id', '=', 'buildings.campus_id')
            ->where('campuses.is_active', true)
            ->whereNull('campuses.deleted_at')
            ->orderBy('campuses.code')
            ->orderBy('buildings.code')
            ->get(['buildings.id', 'buildings.code', 'buildings.name', 'buildings.is_active', 'campuses.code as campus_code'])
            ->map(fn (Building $building): array => [
                'id' => $building->id,
                'name' => "{$building->getAttribute('campus_code')} · {$building->code} · {$building->name}".($building->is_active ? '' : ' (inactivo)'),
            ])
            ->all();
    }

    /**
     * El edificio que se va a editar, sin los borrados. El id llega del navegador: uno que no es uuid no
     * existe (ADR-0014).
     *
     * @throws ModelNotFoundException<Building>
     */
    public function findOrFail(string $id): Building
    {
        return KeyLookup::findOrFail(Building::query(), $id);
    }
}
