<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de carreras para las pantallas.
 */
final class CareerQuery
{
    /**
     * La tabla del CRUD de Carreras, por clave y sin las borradas, con su departamento y cuántos
     * planes sin borrar tiene (`study_plans_count`). `offered_here` dice si se ofrece, con la oferta
     * activa, en `$campus`; es `null` si no hay campus. Busca `$search` en la clave o el nombre con
     * TextSearch; `$departmentId` y `$active` filtran y `null` trae todo. Quién la ve lo decide
     * CareerPolicy en el componente.
     *
     * @return LengthAwarePaginator<int, Career>
     */
    public function forTable(?Campus $campus, string $search = '', ?string $departmentId = null, ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        $page = Career::query()
            ->with('department:id,code,name')
            ->withCount('studyPlans')
            ->when($campus !== null, fn (Builder $query) => $query->withExists([
                'offerings as offered_here' => fn (Builder $offerings) => $offerings->where('campus_id', $campus->id)->where('is_active', true),
            ]))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);

        if ($campus === null) {
            $page->getCollection()->each(fn (Career $career) => $career->setAttribute('offered_here', null));
        }

        return $page;
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
