<?php

namespace App\Modules\Facilities\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Lecturas de salones y de sus bloqueos para las pantallas. Son de un campus, el activo de la credencial,
 * y los ve quien lo cubre según RoomPolicy: el admin y la Subdirección de ese campus.
 */
final class RoomQuery
{
    /** Valor del filtro de dueño para los salones sin departamento dueño. */
    public const string WITHOUT_OWNER = 'sin_dueno';

    /**
     * La tabla de salones del campus, por clave y sin los borrados, con su edificio, su dueño y cuántos
     * bloqueos tiene (`blocks_count`). Busca `$search` en la clave o el nombre con TextSearch; filtra por
     * edificio, tipo, dueño (un id o WITHOUT_OWNER) y estado. Los filtros llegan del navegador: uno que no
     * es uuid o no es un tipo no encuentra a nadie, en lugar de tronar en PostgreSQL (ADR-0014).
     *
     * @return LengthAwarePaginator<int, Room>
     */
    public function forTable(
        ScopedContext $context,
        Campus $campus,
        string $search = '',
        ?string $building = null,
        ?string $type = null,
        ?string $owner = null,
        ?bool $active = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return Room::query()
            ->where('campus_id', $campus->getKey())
            ->unless($context->covers(Role::Subdireccion, $campus), fn (Builder $query) => $query->whereRaw('false'))
            ->with(['building:id,code,name', 'department:id,code,name'])
            ->withCount('blocks')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($building, fn (Builder $query, string $id) => Str::isUuid($id) ? $query->where('building_id', $id) : $query->whereRaw('false'))
            ->when($type, fn (Builder $query, string $value) => RoomType::tryFrom($value) === null ? $query->whereRaw('false') : $query->where('type', $value))
            ->when($owner, fn (Builder $query, string $value) => match (true) {
                $value === self::WITHOUT_OWNER => $query->whereNull('department_id'),
                Str::isUuid($value) => $query->where('department_id', $value),
                default => $query->whereRaw('false'),
            })
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * El salón que se va a editar, sin los borrados.
     *
     * @throws ModelNotFoundException<Room>
     */
    public function findOrFail(string $id): Room
    {
        return KeyLookup::findOrFail(Room::query(), $id);
    }

    /**
     * El bloqueo que se va a quitar, sin los quitados, con su salón (la policy lo autoriza por él).
     *
     * @throws ModelNotFoundException<RoomBlock>
     */
    public function findBlockOrFail(string $id): RoomBlock
    {
        return KeyLookup::findOrFail(RoomBlock::query()->with('room'), $id);
    }

    /**
     * El bloqueo, sin los quitados, o null si ya no existe (quitarlo dos veces no es un error).
     */
    public function findBlock(string $id): ?RoomBlock
    {
        return KeyLookup::find(RoomBlock::query()->with('room'), $id);
    }

    /**
     * Los departamentos que se pueden elegir como dueño: los activos y, al editar, el que el salón ya tiene
     * aunque esté desactivado.
     *
     * @return list<array{id: string, name: string}>
     */
    public function ownerOptions(?string $current = null): array
    {
        return Department::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($current !== null && Str::isUuid($current), fn (Builder $query) => $query->orWhere('id', $current)))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => "{$department->code} · {$department->name}".($department->is_active ? '' : ' (inactivo)'),
            ])
            ->all();
    }

    /**
     * Los bloqueos del salón, por día y hora, con su periodo.
     *
     * @return Collection<int, RoomBlock>
     */
    public function blocksOf(Room $room): Collection
    {
        return $room->blocks()->with('term:id,code,name')->orderBy('day')->orderBy('starts_at')->get();
    }

    /**
     * Los tipos de salón como opciones de x-select.
     *
     * @return list<array{id: string, name: string}>
     */
    public function typeOptions(): array
    {
        return array_map(fn (RoomType $type): array => ['id' => $type->value, 'name' => $type->label()], RoomType::cases());
    }

    /**
     * Los periodos, del más reciente al más antiguo, como opciones de x-select.
     *
     * @return list<array{id: string, name: string}>
     */
    public function termOptions(): array
    {
        return Term::query()->orderByDesc('code')->get(['id', 'code', 'name'])
            ->map(fn (Term $term): array => ['id' => $term->id, 'name' => "{$term->code} · {$term->name}"])
            ->all();
    }
}
