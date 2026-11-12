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
use Illuminate\Support\Collection;

/**
 * Lecturas de edificios para las pantallas. Cada edificio es de un campus y la pantalla los muestra de todos los
 * campus que el usuario ve (#415, la credencial ya no elige campus). Los ve quien los cubre según BuildingPolicy: el
 * admin y la Subdirección de ese campus; a cualquier otro, ninguno.
 */
final class BuildingQuery
{
    /**
     * La tabla de edificios de los campus de `$campuses` (los de la pantalla: todos los del usuario o el que filtró),
     * por clave y sin los borrados, con su campus y cuántos salones sin borrar tiene cada uno (`rooms_count`). Busca `$search` en la clave o el nombre con TextSearch; `$active` filtra por
     * estado y `null` trae los dos.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return LengthAwarePaginator<int, Building>
     */
    public function forTable(Actor $context, Collection $campuses, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Building::query()
            ->whereIn('campus_id', $campuses->pluck('id')->all())
            ->tap(fn (Builder $query) => $context->constrain($query, FacilitiesPermission::BuildingView, ['campus' => 'buildings.campus_id']))
            ->with('campus:id,code,name')
            ->withCount('rooms')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los edificios sin borrar de los campus de `$campuses`, por campus y clave, como opciones de x-select con el
     * código del campus en `campus` para agruparlas: «A · Edificio A»; los inactivos también, porque un salón puede
     * seguir en uno, y lo dicen.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return list<array{id: string, name: string, campus: string}>
     */
    public function options(Collection $campuses): array
    {
        return Building::query()
            ->whereIn('campus_id', $campuses->pluck('id')->all())
            ->with('campus:id,code')
            ->orderBy('code')
            ->get(['id', 'campus_id', 'code', 'name', 'is_active'])
            ->sortBy(fn (Building $building): string => "{$building->campus->code}|{$building->code}")
            ->map(fn (Building $building): array => [
                'id' => $building->id,
                'name' => "{$building->code} · {$building->name}".($building->is_active ? '' : ' (inactivo)'),
                'campus' => $building->campus->code,
            ])
            ->values()
            ->all();
    }

    /**
     * El edificio sin borrar con ese id si es de uno de los campus de `$campuses`; si no (otro campus, no existe o no
     * es uuid), null.
     *
     * @param  Collection<int, Campus>  $campuses
     */
    public function findInCampuses(string $id, Collection $campuses): ?Building
    {
        return KeyLookup::find(Building::query()->whereIn('campus_id', $campuses->pluck('id')->all())->with('campus'), $id);
    }

    /**
     * Los edificios sin borrar de los campus activos, agrupados por campus (por clave) y por clave, para el select con
     * grupos que mueve un salón a un edificio de otro campus al validar los catálogos (CV2, CV-S10): el campus sigue al
     * edificio. Cada edificio dice «A · Edificio A» y, si está inactivo, lo dice.
     *
     * @return list<array{id: string, code: string, name: string, buildings: list<array{id: string, name: string}>}>
     */
    public function groupedByActiveCampus(): array
    {
        return Building::query()
            ->join('campuses', 'campuses.id', '=', 'buildings.campus_id')
            ->where('campuses.is_active', true)
            ->whereNull('campuses.deleted_at')
            ->orderBy('campuses.code')
            ->orderBy('buildings.code')
            ->get(['buildings.id', 'buildings.code', 'buildings.name', 'buildings.is_active', 'campuses.id as campus_id', 'campuses.code as campus_code', 'campuses.name as campus_name'])
            ->groupBy('campus_id')
            ->map(fn ($buildings): array => [
                'id' => $buildings->first()->getAttribute('campus_id'),
                'code' => $buildings->first()->getAttribute('campus_code'),
                'name' => $buildings->first()->getAttribute('campus_name'),
                'buildings' => $buildings->map(fn (Building $building): array => [
                    'id' => $building->id,
                    'name' => "{$building->code} · {$building->name}".($building->is_active ? '' : ' (inactivo)'),
                ])->values()->all(),
            ])
            ->values()
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
