<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Lecturas de planes de estudio para las pantallas.
 */
final class StudyPlanQuery
{
    /**
     * La tabla del CRUD de Planes, por clave y sin los borrados, con su carrera (aunque esté borrada)
     * y cuántas materias tiene en su retícula (`placements_count`, sin las quitadas). Busca `$search`
     * en la clave o el nombre con TextSearch; `$careerId` filtra por carrera (un id que no es uuid no
     * trae nada) y `$active` por estado; `null` no filtra. Quién la ve lo decide StudyPlanPolicy en
     * el componente.
     *
     * @return LengthAwarePaginator<int, StudyPlan>
     */
    public function forTable(string $search = '', ?string $careerId = null, ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return StudyPlan::query()
            ->with(['career' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name'])])
            ->withCount('placements')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($careerId !== null, fn (Builder $query) => $query->whereIn('career_id', Str::isUuid($careerId) ? [$careerId] : []))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * El plan que se va a editar, activar o desactivar, sin los borrados.
     *
     * @throws ModelNotFoundException<StudyPlan>
     */
    public function findOrFail(string $id): StudyPlan
    {
        return KeyLookup::findOrFail(StudyPlan::query(), $id);
    }

    /**
     * El plan de la retícula (academic::pages.plan), sin los borrados, con su carrera aunque esté borrada.
     *
     * @throws ModelNotFoundException<StudyPlan>
     */
    public function findWithCareerOrFail(string $id): StudyPlan
    {
        return KeyLookup::findOrFail(
            StudyPlan::query()->with(['career' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name'])]),
            $id,
        );
    }
}
