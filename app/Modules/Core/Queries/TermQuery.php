<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Lecturas de periodos para las pantallas.
 */
final class TermQuery
{
    /**
     * Los periodos que ofrece el selector de la credencial: todos los existentes, del más reciente al
     * más antiguo.
     *
     * @return Collection<int, Term>
     */
    public function selectable(): Collection
    {
        return Term::query()->orderByDesc('starts_on')->get();
    }

    /**
     * La tabla del catálogo de periodos, del más reciente al más antiguo. Solo la ve quien TermPolicy
     * deja (admin y subdirección); a cualquier otro le llega vacía. Busca `$search` en el código o el
     * nombre con TextSearch.
     *
     * @return LengthAwarePaginator<int, Term>
     */
    public function forTable(ScopedContext $context, string $search = '', int $perPage = 15): LengthAwarePaginator
    {
        return Term::query()
            ->unless(Gate::forUser($context->user())->allows('viewAny', Term::class), fn (Builder $query) => $query->whereRaw('false'))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->orderByDesc('starts_on')
            ->paginate($perPage);
    }

    /**
     * El periodo elegido en la credencial.
     *
     * @throws ModelNotFoundException<Term>
     */
    public function findOrFail(string $id): Term
    {
        return KeyLookup::findOrFail(Term::query(), $id);
    }
}
