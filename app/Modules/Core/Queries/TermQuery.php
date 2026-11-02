<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * En qué campus se abrió el periodo, por código de campus, con el campus (también uno desactivado
     * o borrado después) y quién lo abrió, para la ficha del periodo.
     *
     * @return Collection<int, TermOpening>
     */
    public function openings(Term $term): Collection
    {
        return $term->openings()
            ->with(['campus' => fn (BelongsTo $query) => $query->withTrashed(), 'opener'])
            ->join('campuses', 'campuses.id', '=', 'term_openings.campus_id')
            ->orderBy('campuses.code')
            ->select('term_openings.*')
            ->get();
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
