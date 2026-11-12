<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
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
     * En qué modalidades se abrió el periodo (M4), por clave, con la modalidad (también una desactivada o borrada
     * después) y quién lo abrió, para la ficha del periodo.
     *
     * @return Collection<int, TermOpening>
     */
    public function openings(Term $term): Collection
    {
        return $term->openings()
            ->with(['modality' => fn (BelongsTo $query) => $query->withTrashed(), 'opener'])
            ->join('modalities', 'modalities.id', '=', 'term_openings.modality_id')
            ->orderBy('modalities.code')
            ->select('term_openings.*')
            ->get();
    }

    /**
     * Los renglones de la sección «Modalidades» de la ficha, por clave: cada modalidad activa (`opening` es null si el
     * periodo no se ha abierto en ella) y, solo lectura (`actionable` falso), las modalidades donde se abrió y que ya
     * están inactivas o borradas.
     *
     * @param  Collection<int, Modality>  $active
     * @return Collection<int, array{modality: Modality, opening: ?TermOpening, actionable: bool}>
     */
    public function modalityRows(Term $term, Collection $active): Collection
    {
        $openings = $this->openings($term)->keyBy('modality_id');

        return $active
            ->map(fn (Modality $modality): array => ['modality' => $modality, 'opening' => $openings->get($modality->id), 'actionable' => true])
            ->concat(
                $openings
                    ->reject(fn (TermOpening $opening): bool => $active->contains('id', $opening->modality_id))
                    ->map(fn (TermOpening $opening): array => ['modality' => $opening->modality, 'opening' => $opening, 'actionable' => false])
            )
            ->sortBy(fn (array $row): string => $row['modality']->code)
            ->values();
    }

    /**
     * Los periodos que se abrieron en una modalidad, del más reciente al más antiguo.
     *
     * @return Collection<int, Term>
     */
    public function openedIn(Modality $modality): Collection
    {
        return Term::query()
            ->whereIn('id', TermOpening::query()->where('modality_id', $modality->getKey())->select('term_id'))
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
    public function forTable(Actor $context, string $search = '', int $perPage = 15): LengthAwarePaginator
    {
        return Term::query()
            ->with(['openings.modality' => fn (BelongsTo $query) => $query->withTrashed()])
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
