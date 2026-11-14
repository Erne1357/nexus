<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecturas del panel «Avisos y atajos» (CF-S6): todos los avisos y atajos sin borrar, activos o no, en el orden en que
 * salen en la pantalla de acceso. Lo que ve esa pantalla lo da LoginScreenQuery; aquí cada fila trae su estado de
 * vigencia (`visibilityAt()`, la misma regla de LG-01 y LG-02) para decir si se ve hoy.
 */
final class LoginContentQuery
{
    /**
     * @return Collection<int, LoginNotice>
     */
    public function notices(string $search = '', ?bool $active = null): Collection
    {
        return $this->list(LoginNotice::query(), $search, $active);
    }

    /**
     * @return Collection<int, LoginShortcut>
     */
    public function shortcuts(string $search = '', ?bool $active = null): Collection
    {
        return $this->list(LoginShortcut::query(), $search, $active);
    }

    public function findNotice(string $id): LoginNotice
    {
        return KeyLookup::findOrFail(LoginNotice::query(), $id);
    }

    public function findShortcut(string $id): LoginShortcut
    {
        return KeyLookup::findOrFail(LoginShortcut::query(), $id);
    }

    /**
     * @template TModel of LoginNotice|LoginShortcut
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function list(Builder $query, string $search, ?bool $active): Collection
    {
        return $query
            ->tap(fn (Builder $q) => TextSearch::apply($q, ['title'], $search))
            ->when($active !== null, fn (Builder $q) => $q->where('is_active', $active))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
