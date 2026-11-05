<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecturas de «Personas y puestos»: la lista de todos los usuarios con sus puestos vigentes, la búsqueda de personas,
 * quién ocupa un puesto hoy y las opciones de alcance que ofrece cada puesto.
 */
final class PeopleQuery
{
    public function __construct(
        private readonly RoleAssignmentRules $rules,
        private readonly ScopeLabels $labels,
    ) {}

    /**
     * La tabla de personas, por nombre, con sus nombramientos vigentes. Busca `$search` en el nombre o el usuario con
     * TextSearch; `$role` deja a quienes tienen ese puesto vigente.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function forTable(string $search = '', ?Role $role = null, int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['name', 'username'], $search))
            ->when($role !== null, fn (Builder $query) => $query->whereHas(
                'roleAssignments',
                fn (Builder $assignments) => $assignments->current()->where('role', $role),
            ))
            ->with(['roleAssignments' => fn ($assignments) => $assignments->current()->orderBy('valid_from')])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @throws ModelNotFoundException<User>
     */
    public function findOrFail(string $id): User
    {
        return KeyLookup::findOrFail(User::query(), $id);
    }

    /**
     * Las personas cuyo nombre o usuario contiene `$term`, por nombre; sin término, ninguna.
     *
     * @return Collection<int, User>
     */
    public function search(string $term, int $limit = 8): Collection
    {
        if (trim($term) === '') {
            return new Collection;
        }

        return User::query()
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['name', 'username'], $term))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Los nombramientos vigentes del puesto sobre ese alcance, con su persona. Compara solo las columnas que el
     * puesto lleva (`Role::scopeColumns()`); si falta alguna, ninguno.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function holders(Role $role, ?string $campusId, ?string $unitId, ?string $careerId): Collection
    {
        $values = ['campus_id' => $campusId, 'org_unit_id' => $unitId, 'career_id' => $careerId];
        $query = RoleAssignment::query()->current()->where('role', $role)->with('user')->orderBy('valid_from')->orderBy('id');

        foreach ($role->scopeColumns() as $column) {
            if (($values[$column] ?? null) === null || ! Str::isUuid($values[$column])) {
                return new Collection;
            }

            $query->where($column, $values[$column]);
        }

        return $query->get();
    }

    /**
     * Lo que se elige al nombrar o al preguntar quién ocupa un puesto: los campus activos («C2 · Campus II»), las
     * unidades activas que el puesto admite y las carreras que se ofrecen en el campus (ninguna sin campus).
     *
     * @return array{campuses: list<array{id: string, name: string}>, units: list<array{id: string, name: string}>, careers: list<array{id: string, name: string}>}
     */
    public function scopeOptions(Role $role, ?string $campusId): array
    {
        $campuses = Campus::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])
            ->all();

        $units = $role->unitKind() === null ? [] : OrgUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (OrgUnit $unit): bool => $this->rules->allowsUnit($role, $unit))
            ->map(fn (OrgUnit $unit): array => ['id' => $unit->id, 'name' => $unit->name])
            ->values()
            ->all();

        $careers = [];

        if ($campusId !== null && Str::isUuid($campusId)) {
            foreach ($this->labels->careerOptions($campusId) as $id => $name) {
                $careers[] = ['id' => (string) $id, 'name' => $name];
            }
        }

        return ['campuses' => $campuses, 'units' => $units, 'careers' => $careers];
    }

    /**
     * Un campus, borrados incluidos, para que Actions y reglas respondan en español si ya no sirve; nulo si el id no
     * es de uno.
     */
    public function campus(?string $id): ?Campus
    {
        return $id === null || $id === '' ? null : KeyLookup::find(Campus::withTrashed(), $id);
    }

    /**
     * Una unidad, borrados incluidos; nulo si el id no es de una.
     */
    public function unit(?string $id): ?OrgUnit
    {
        return $id === null || $id === '' ? null : KeyLookup::find(OrgUnit::withTrashed(), $id);
    }
}
