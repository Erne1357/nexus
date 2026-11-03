<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas de campus para las pantallas.
 */
final class CampusQuery
{
    /**
     * La tabla del CRUD de Campus, por código y sin los borrados. Al admin, todos, activos e
     * inactivos; a cualquier otro, los campus sobre los que tiene `core.campus.view` (ScopedContext::constrain()):
     * así ni el alcance ni el permiso se repiten aquí. Busca `$search`
     * en el código o el nombre con TextSearch; `$active` filtra por estado y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Campus>
     */
    public function forTable(ScopedContext $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Campus::query()
            ->tap(fn (Builder $query) => $context->constrain($query, CorePermission::CampusView, ['campus' => 'campuses.id']))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los campus activos, por código, como opciones de x-select: «C2 · Campus II».
     *
     * @return list<array{id: string, name: string}>
     */
    public function activeOptions(): array
    {
        return Campus::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])
            ->all();
    }

    /**
     * Los campus activos, por código: los que se ofrecen al fijar la ventana propia de una etapa.
     *
     * @return Collection<int, Campus>
     */
    public function active(): Collection
    {
        return Campus::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * El campus que se va a editar, sin los borrados.
     *
     * @throws ModelNotFoundException<Campus>
     */
    public function findOrFail(string $id): Campus
    {
        return KeyLookup::findOrFail(Campus::query(), $id);
    }
}
