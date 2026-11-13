<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de campus para las pantallas.
 */
final class CampusQuery
{
    public function __construct(private readonly ModalityQuery $modalities) {}

    /**
     * La tabla del CRUD de Campus, por código y sin los borrados. Al admin, todos, activos e
     * inactivos; a cualquier otro, los campus sobre los que tiene `core.campus.view` (Actor::constrain()):
     * así ni el alcance ni el permiso se repiten aquí. Busca `$search`
     * en el código o el nombre con TextSearch; `$active` filtra por estado y `null` trae los dos.
     *
     * @return LengthAwarePaginator<int, Campus>
     */
    public function forTable(Actor $context, string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Campus::query()
            ->tap(fn (Builder $query) => $context->constrain($query, CorePermission::CampusView, ['campus' => 'campuses.id']))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los campus activos, por código, como opciones de x-select: «C2 · Campus II».
     *
     * @return list<array{id: string, name: string}>
     */
    public function activeOptions(): array
    {
        return Campus::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])
            ->all();
    }

    /**
     * Los campus activos, por código: los que se ofrecen al fijar la ventana propia de una etapa, y los lugares de los
     * grupos de una modalidad con salones (of(), openIn()).
     *
     * @return EloquentCollection<int, Campus>
     */
    public function active(): EloquentCollection
    {
        return Campus::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * Los campus donde se dan los grupos de una modalidad (MS4, supuesto por confirmar): con salones, los activos por
     * código, porque un grupo de esa modalidad se da en cualquiera; sin salones, ninguno (sus grupos van sin campus).
     * Una modalidad con perfil inválido cuenta como sin salones (Modality::valid_profile). El campus es solo el lugar
     * (MS3): no dice de qué modalidad es.
     *
     * @return EloquentCollection<int, Campus>
     */
    public function of(Modality $modality): EloquentCollection
    {
        return $modality->valid_profile?->usesRooms === true ? $this->active() : new EloquentCollection;
    }

    /**
     * Los campus donde el periodo está abierto (M4): los activos, por código, si se abrió en alguna modalidad sin borrar
     * con salones (ModalityQuery::openedWithRooms); si no, ninguno.
     *
     * @return EloquentCollection<int, Campus>
     */
    public function openIn(Term|string $term): EloquentCollection
    {
        return $this->modalities->openedWithRooms($term)->isNotEmpty() ? $this->active() : new EloquentCollection;
    }

    /**
     * El campus que se va a editar, sin los borrados.
     *
     * @throws ModelNotFoundException<Campus>
     */
    public function findOrFail(string $id): Campus
    {
        return KeyLookup::findOrFail(Campus::query(), $id);
    }
}
