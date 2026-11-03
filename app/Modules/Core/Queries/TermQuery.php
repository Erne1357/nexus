<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
     * El código más reciente que existe (el mayor, que sigue el orden cronológico del SII); null si no hay periodos.
     */
    public function latestCode(): ?string
    {
        return Term::query()->max('code');
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
     * Los renglones de la sección «Campus» de la ficha, por código: cada campus activo (`opening` es null si el
     * periodo no se ha abierto en él) y, solo lectura (`actionable` falso), los campus donde se abrió y que ya
     * están inactivos o borrados.
     *
     * @param  Collection<int, Campus>  $active
     * @return Collection<int, array{campus: Campus, opening: ?TermOpening, actionable: bool}>
     */
    public function campusRows(Term $term, Collection $active): Collection
    {
        $openings = $this->openings($term)->keyBy('campus_id');

        return $active
            ->map(fn (Campus $campus): array => ['campus' => $campus, 'opening' => $openings->get($campus->id), 'actionable' => true])
            ->concat(
                $openings
                    ->reject(fn (TermOpening $opening): bool => $active->contains('id', $opening->campus_id))
                    ->map(fn (TermOpening $opening): array => ['campus' => $opening->campus, 'opening' => $opening, 'actionable' => false])
            )
            ->sortBy(fn (array $row): string => $row['campus']->code)
            ->values();
    }

    /**
     * Los periodos que se abrieron en un campus, del más reciente al más antiguo.
     *
     * @return Collection<int, Term>
     */
    public function openedIn(Campus $campus): Collection
    {
        return Term::query()
            ->whereIn('id', TermOpening::query()->where('campus_id', $campus->getKey())->select('term_id'))
            ->orderByDesc('starts_on')
            ->get();
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
            ->with(['openings.campus' => fn (BelongsTo $query) => $query->withTrashed()])
            ->unless($context->allowsAny(CorePermission::TermView), fn (Builder $query) => $query->whereRaw('false'))
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
