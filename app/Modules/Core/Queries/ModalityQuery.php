<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Carbon\CarbonImmutable;
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
     * códigos de los periodos vigentes y futuros abiertos en ella (`terms`; los que terminan hoy o después en la zona del
     * Tec, como la guarda, M13) y cuántos nombramientos vigentes hoy la llevan (`appointments`).
     *
     * @param  list<string>  $ids
     * @return array<string, array{terms: list<string>, appointments: int}>
     */
    public function deactivationImpact(array $ids): array
    {
        $impact = array_fill_keys($ids, ['terms' => [], 'appointments' => 0]);

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        TermOpening::query()
            ->whereIn('modality_id', $ids)
            ->whereIn('term_id', Term::query()->whereDate('ends_on', '>=', $today)->select('id'))
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
     * La jornada de los salones: la ventana que cubre a las modalidades activas con salones (ModalityProfile::covering;
     * MS34, supuesto por confirmar); una con perfil inválido no cuenta. Un salón es de un campus, que es solo el lugar
     * (MS3), y lo usan los grupos de toda modalidad con salones (MS4), así que su jornada es la de todas ellas. Con solo
     * Presencial activa es lunes a viernes de 7:00 a 21:00; sin ninguna, el perfil presencial por omisión.
     */
    public function roomsWindow(): ModalityProfile
    {
        return ModalityProfile::covering(
            Modality::query()->where('is_active', true)->get()
                ->map(fn (Modality $modality): ?ModalityProfile => $modality->valid_profile)
                ->filter(fn (?ModalityProfile $profile): bool => $profile?->usesRooms === true),
        );
    }

    /**
     * Las modalidades sin borrar donde se abrió el periodo y cuyos grupos se dan en un campus, por clave: las que usan
     * salones (MS4); una con perfil inválido cuenta como sin salones (Modality::valid_profile). De ellas salen los
     * campus donde el periodo está abierto (CampusQuery::openIn) y las cargas del tablero de validación.
     *
     * @return Collection<int, Modality>
     */
    public function openedWithRooms(Term|string $term): Collection
    {
        return Modality::query()
            ->whereIn('id', TermOpening::query()->where('term_id', $term instanceof Term ? $term->getKey() : $term)->select('modality_id'))
            ->orderBy('code')
            ->get()
            ->filter(fn (Modality $modality): bool => $modality->valid_profile?->usesRooms === true)
            ->values();
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
