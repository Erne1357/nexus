<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas del catálogo de modalidades (ADR-0025) para las pantallas.
 */
final class ModalityQuery
{
    /**
     * La tabla del catálogo de Modalidades, por clave y sin las borradas. Al admin, todas, activas e inactivas; a
     * cualquier otro, las modalidades sobre las que tiene `core.modality.view` (Actor::constrain()), así ni el alcance
     * ni el permiso se repiten aquí. Busca `$search` en la clave o el nombre con TextSearch; `$active` filtra por estado
     * y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Modality>
     */
    public function forTable(Actor $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Modality::query()
            ->tap(fn (Builder $query) => $context->constrain($query, CorePermission::ModalityView, ['modality' => 'modalities.id']))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Lo que desactivar cada modalidad deja a medias, para el texto de la confirmación (M11), por id de modalidad: los
     * códigos de los periodos abiertos en ella (`terms`) y cuántos nombramientos vigentes hoy la llevan
     * (`appointments`).
     *
     * @param  list<string>  $ids
     * @return array<string, array{terms: list<string>, appointments: int}>
     */
    public function deactivationImpact(array $ids): array
    {
        $impact = array_fill_keys($ids, ['terms' => [], 'appointments' => 0]);

        TermOpening::query()
            ->whereIn('modality_id', $ids)
            ->with('term:id,code')
            ->get()
            ->each(function (TermOpening $opening) use (&$impact): void {
                $impact[$opening->modality_id]['terms'][] = $opening->term->code;
            });

        foreach ($impact as $id => $row) {
            sort($row['terms']);
            $impact[$id]['terms'] = $row['terms'];
        }

        RoleAssignment::query()->current()->whereIn('modality_id', $ids)
            ->selectRaw('modality_id, count(*) as total')->groupBy('modality_id')->get()
            ->each(function (RoleAssignment $row) use (&$impact): void {
                $impact[$row->modality_id]['appointments'] = (int) $row->total;
            });

        return $impact;
    }

    /**
     * Las modalidades activas y sin borrar, por clave: donde se abre el periodo y se fija una ventana propia (M4).
     *
     * @return Collection<int, Modality>
     */
    public function active(): Collection
    {
        return Modality::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * La modalidad elegida, sin las borradas.
     *
     * @throws ModelNotFoundException<Modality>
     */
    public function findOrFail(string $id): Modality
    {
        return KeyLookup::findOrFail(Modality::query(), $id);
    }
}
