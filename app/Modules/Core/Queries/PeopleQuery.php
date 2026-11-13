<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
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
     * TextSearch; `$role` deja a quienes tienen ese puesto vigente. Sin búsqueda ni puesto no salen los alumnos que no
     * tienen otro puesto vigente (AC-15, supuesto por confirmar): son decenas de miles y la lista es del personal; salen
     * al buscarlos o con el puesto «Alumno».
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
            ->when($role === null && trim($search) === '', fn (Builder $query) => $query->where(fn (Builder $staff) => $staff
                ->whereDoesntHave('roleAssignments', fn (Builder $assignments) => $assignments->current()->where('role', Role::Alumno))
                ->orWhereHas('roleAssignments', fn (Builder $assignments) => $assignments->current()->where('role', '<>', Role::Alumno))))
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
     * puesto lleva (`Role::scopeColumns()`); si falta alguna, ninguno. El campus opcional (AZ-S17), si se elige, deja
     * a los de ese campus y a los de todos los campus de la modalidad (los que no fijan campus); si no, a todos.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function holders(Role $role, ?string $campusId, ?string $unitId, ?string $careerId, ?string $modalityId = null): Collection
    {
        $values = ['campus_id' => $campusId, 'org_unit_id' => $unitId, 'career_id' => $careerId, 'modality_id' => $modalityId];
        $query = RoleAssignment::query()->current()->where('role', $role)->with('user')->orderBy('valid_from')->orderBy('id');

        foreach ($role->scopeColumns() as $column) {
            if (($values[$column] ?? null) === null || ! Str::isUuid($values[$column])) {
                return new Collection;
            }

            $query->where($column, $values[$column]);
        }

        if (in_array('campus_id', $role->optionalScopeColumns(), true) && $campusId !== null && Str::isUuid($campusId)) {
            $query->where(fn (Builder $campus) => $campus->where('campus_id', $campusId)->orWhereNull('campus_id'));
        }

        return $query->get();
    }

    /**
     * Lo que se elige al nombrar o al preguntar quién ocupa un puesto: las modalidades activas («Presencial»), los
     * campus activos («C2 · Campus II»), las unidades activas que el puesto admite y las carreras que se ofrecen en el
     * campus. Sin campus, ninguna carrera, salvo al puesto que pide carrera con el campus opcional (el coordinador,
     * AZ-S17): las que se ofrecen en algún campus activo. Con `$modalityId` de una modalidad sin salones (o con perfil
     * inválido), ningún campus: sus grupos no tienen (MS4) y el nombramiento va sin campus (RoleAssignmentRules).
     *
     * @return array{modalities: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>, units: list<array{id: string, name: string}>, careers: list<array{id: string, name: string}>}
     */
    public function scopeOptions(Role $role, ?string $campusId, ?string $modalityId = null): array
    {
        $modalities = $this->modalityOptions();

        $campuses = $this->usesCampuses($modalityId) ? Campus::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])
            ->all() : [];

        $units = $role->unitKind() === null ? [] : OrgUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (OrgUnit $unit): bool => $this->rules->allowsUnit($role, $unit))
            ->map(fn (OrgUnit $unit): array => ['id' => $unit->id, 'name' => $unit->name])
            ->values()
            ->all();

        $careers = [];
        $campusId = $campusId !== null && Str::isUuid($campusId) ? $campusId : null;

        if ($campusId !== null || (in_array('career_id', $role->scopeColumns(), true) && in_array('campus_id', $role->optionalScopeColumns(), true))) {
            foreach ($this->labels->careerOptions($campusId) as $id => $name) {
                $careers[] = ['id' => (string) $id, 'name' => $name];
            }
        }

        return ['modalities' => $modalities, 'campuses' => $campuses, 'units' => $units, 'careers' => $careers];
    }

    /**
     * Si en la modalidad elegida un nombramiento lleva campus: sí sin modalidad (o con una que no existe), no en una sin
     * salones o con perfil inválido (MS4; Modality::valid_profile).
     */
    public function usesCampuses(?string $modalityId): bool
    {
        $modality = $modalityId === null || ! Str::isUuid($modalityId) ? null : $this->modality($modalityId);

        return $modality === null || $modality->valid_profile?->usesRooms === true;
    }

    /**
     * La lista con casillas de «Nombrar» al coordinador (U3; M5, MS19, AZ-S17): las carreras que se ofrecen en el campus
     * (sin campus, en algún campus activo), por departamento, cada una con quién la coordina hoy en esa modalidad.
     * `state` es `mine` si `$person` ya la coordina en el alcance elegido y no hace falta nombrarla otra vez (con campus,
     * en ese campus o en todos; sin campus, solo en todos: ampliar a una coordinadora de C2 a todos los campus sí se
     * puede), `held` si la coordina otra persona y `free` si nadie. `holders` lo dice en español («Daniel Ríos · todos
     * los campus», «Sin coordinador en C2», «Ya la coordina en C2»). `visible` es falso para las carreras que `$search`
     * (clave o nombre) no deja ver; la lista siempre trae todas para que los atajos y las cuentas no dependan de la
     * búsqueda.
     *
     * @return list<array{id: string, code: string, name: string, careers: list<array{id: string, code: string, name: string, state: string, holders: string, visible: bool}>}>
     */
    public function careerChecklist(?string $campusId, ?string $modalityId, ?User $person = null, string $search = ''): array
    {
        $campusId = $campusId !== null && Str::isUuid($campusId) ? $campusId : null;
        $modalityId = $modalityId !== null && Str::isUuid($modalityId) ? $modalityId : null;
        $campusCode = $campusId === null ? null : Campus::withTrashed()->whereKey($campusId)->value('code');
        $codes = Campus::withTrashed()->pluck('code', 'id');

        $groups = $this->labels->careerGroups($campusId);
        $careerIds = array_merge([], ...array_map(fn (array $group): array => array_column($group['careers'], 'id'), $groups));

        $held = $careerIds === [] || $modalityId === null ? collect() : RoleAssignment::query()->current()
            ->where('role', Role::Coordinador)
            ->where('modality_id', $modalityId)
            ->whereIn('career_id', $careerIds)
            ->when($campusId !== null, fn (Builder $query) => $query->where(fn (Builder $campus) => $campus->where('campus_id', $campusId)->orWhereNull('campus_id')))
            ->with('user:id,name')
            ->orderBy('valid_from')->orderBy('id')
            ->get()
            ->groupBy('career_id');

        $term = mb_strtolower(trim($search));
        $result = [];

        foreach ($groups as $group) {
            $careers = [];

            foreach ($group['careers'] as $career) {
                $holders = $held->get($career['id'], collect());
                $own = $person === null ? null : $holders->where('user_id', $person->getKey())->sortBy(fn (RoleAssignment $assignment): int => $assignment->campus_id === null ? 0 : 1)->first(
                    fn (RoleAssignment $assignment): bool => $campusId !== null || $assignment->campus_id === null,
                );
                $names = $holders->map(fn (RoleAssignment $assignment): string => $assignment->user->name.' · '.($assignment->campus_id === null ? 'todos los campus' : $codes->get($assignment->campus_id, '')))->unique()->implode(', ');
                $careers[] = [
                    ...$career,
                    'state' => $own !== null ? 'mine' : ($holders->isEmpty() ? 'free' : 'held'),
                    'holders' => $own !== null
                        ? 'Ya la coordina en '.($own->campus_id === null ? 'todos los campus' : $codes->get($own->campus_id, ''))
                        : ($names !== '' ? $names : ($campusCode === null ? 'Sin coordinador' : "Sin coordinador en {$campusCode}")),
                    'visible' => $term === '' || str_contains(mb_strtolower($career['code'].' '.$career['name']), $term),
                ];
            }

            $result[] = [...$group, 'careers' => $careers];
        }

        return $result;
    }

    /**
     * Las modalidades activas, por código, para elegir una al nombrar (AZ-S17).
     *
     * @return list<array{id: string, name: string}>
     */
    public function modalityOptions(): array
    {
        return Modality::query()->where('is_active', true)->orderBy('code')->get(['id', 'name'])
            ->map(fn (Modality $modality): array => ['id' => $modality->id, 'name' => $modality->name])
            ->all();
    }

    /**
     * La modalidad que el formulario propone: la única activa; si hay varias o ninguna, ninguna (se elige).
     */
    public function defaultModalityId(): ?string
    {
        $options = $this->modalityOptions();

        return count($options) === 1 ? $options[0]['id'] : null;
    }

    /**
     * Una modalidad, borradas incluidas, para que Actions y reglas respondan en español si ya no sirve; nula si el id
     * no es de una.
     */
    public function modality(?string $id): ?Modality
    {
        return $id === null || $id === '' ? null : KeyLookup::find(Modality::withTrashed(), $id);
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
