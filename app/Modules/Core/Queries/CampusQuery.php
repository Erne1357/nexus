<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Lecturas de campus para las pantallas.
 */
final class CampusQuery
{
    /**
     * La tabla del CRUD de Campus, por código y sin los borrados. Al admin, todos, activos e
     * inactivos; a cualquier otro, sus campus de ScopedContext::campuses() (activos y de su alcance)
     * que CampusPolicy le deja ver: así ni el alcance ni el permiso se repiten aquí. Busca `$search`
     * en el código o el nombre con TextSearch; `$active` filtra por estado y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Campus>
     */
    public function forTable(ScopedContext $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Campus::query()
            ->unless($context->isAdmin(), fn (Builder $query) => $query->whereKey($context->campuses()
                ->filter(fn (Campus $campus): bool => Gate::forUser($context->user())->allows('view', $campus))
                ->pluck('id')
                ->all()))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * El campus que se va a editar, sin los borrados.
     *
     * @throws ModelNotFoundException<Campus>
     */
    public function findOrFail(int $id): Campus
    {
        return Campus::query()->findOrFail($id);
    }
}
