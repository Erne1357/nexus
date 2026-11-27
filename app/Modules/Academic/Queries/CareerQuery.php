<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de carreras para las pantallas.
 */
final class CareerQuery
{
    /**
     * La tabla del CRUD de Carreras, por clave y sin las borradas, con su departamento y cuántos
     * planes sin borrar tiene (`study_plans_count`). `offered_campus_ids` lista los campus de `$campuses` donde se
     * ofrece, con la oferta activa (#415: la oferta se muestra por campus, de todos a la vez). Busca `$search` en la
     * clave o el nombre con TextSearch; `$departmentId` y `$active` filtran y `null` trae todo. Quién la ve lo decide
     * CareerPolicy en el componente.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return LengthAwarePaginator<int, Career>
     */
    public function forTable(Collection $campuses, string $search = '', ?string $departmentId = null, ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        $campusIds = $campuses->pluck('id')->all();

        $page = Career::query()
            ->with(['department:id,code,name', 'offerings' => fn ($offerings) => $offerings->where('is_active', true)->whereIn('campus_id', $campusIds)])
            ->withCount('studyPlans')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);

        $page->getCollection()->each(fn (Career $career) => $career->setAttribute('offered_campus_ids', $career->offerings->pluck('campus_id')->all()));

        return $page;
    }

    /**
     * Las carreras sin borrar, por clave, como opciones de x-select para los filtros y formularios
     * de las pantallas de Academic (Planes): «ISC · Ingeniería en Sistemas»; las inactivas también,
     * porque un plan puede seguir en una, y lo dicen.
     *
     * @return list<array{id: string, name: string}>
     */
    public function options(): array
    {
        return Career::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Career $career): array => [
                'id' => $career->id,
                'name' => "{$career->code} · {$career->name}".($career->is_active ? '' : ' (inactiva)'),
            ])
            ->all();
    }

    /**
     * La carrera que se va a editar, ofrecer o borrar, sin las borradas.
     *
     * @throws ModelNotFoundException<Career>
     */
    public function findOrFail(string $id): Career
    {
        return KeyLookup::findOrFail(Career::query(), $id);
    }
}
