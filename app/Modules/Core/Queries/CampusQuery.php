<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecturas de campus para las pantallas.
 */
final class CampusQuery
{
    /**
     * La tabla del CRUD de Campus, por código: lo que CampusPolicy deja ver (al admin, todos, activos
     * e inactivos; a la subdirección, los de su alcance; a nadie más, ninguno), sin los borrados.
     * Busca `$search` en el código o el nombre sin distinguir mayúsculas; `$active` filtra por estado
     * y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Campus>
     */
    public function forTable(ScopedContext $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        $search = trim($search);

        return Campus::query()
            ->unless($context->isAdmin(), fn (Builder $query) => $query->whereKey(
                $context->scopes(Role::Subdireccion)->pluck('campus_id')->unique()->all(),
            ))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('code', "%{$search}%")
                ->orWhereLike('name', "%{$search}%")))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }
}
