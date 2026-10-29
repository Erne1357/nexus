<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

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
     * El periodo elegido en la credencial.
     *
     * @throws ModelNotFoundException<Term>
     */
    public function findOrFail(string $id): Term
    {
        return KeyLookup::findOrFail(Term::query(), $id);
    }
}
