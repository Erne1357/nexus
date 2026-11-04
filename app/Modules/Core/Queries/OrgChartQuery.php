<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Rules\OrgUnitRules;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas del Organigrama (3.P7, maqueta B): el diagrama, el panel de la unidad elegida y las madres que se ofrecen al
 * dar de alta o mover una unidad. Lee las unidades sin borrar al momento y arma el árbol en PHP, sin OrgTree, que
 * memoriza el mapa por petición y daría el árbol de antes de una escritura en la misma petición.
 *
 * - Titular (AZ-S7): quien ocupa hoy el puesto exclusivo de la unidad (RoleAssignmentRules::titularRoleOf()): el jefe
 *   de un departamento académico y la Subdirección de Subdirección Académica (AZ-S10).
 * - Personas: usuarios distintos con adscripción (UnitAdscriptions, que pone Faculty) o nombramiento vigente en la
 *   unidad o en su subárbol.
 * - Los motivos de bloqueo son los de OrgUnitRules, los mismos que aplican las Actions (AZ-S13).
 */
final class OrgChartQuery
{
    private const string INDENT = "\u{00A0}\u{00A0}\u{00A0}";

    public function __construct(
        private readonly UnitAdscriptions $adscriptions,
        private readonly RoleAssignmentRules $rules,
        private readonly OrgUnitRules $unitRules,
        private readonly AssignmentLabels $labels,
    ) {}

    /**
     * El diagrama: las unidades sin borrar, las hijas por `sort` y luego por nombre. Una inactiva esconde su subárbol,
     * salvo `$withInactive`. Son cuatro consultas, sin importar cuántas unidades haya: las unidades, los titulares
     * vigentes, los usuarios con nombramiento vigente por unidad y las adscripciones.
     */
    public function chart(bool $withInactive = false): OrgChart
    {
        $units = $this->units();
        $childrenOf = $this->childrenOf($units);
        $titulars = $this->currentTitulars();
        $people = $this->peopleCounts($units, $childrenOf, $this->currentHolders());

        $inactive = 0;
        $headless = 0;
        $titularOf = [];

        foreach ($units as $unit) {
            $role = $this->rules->titularRoleOf($unit);
            $titular = $role === null ? null : ($titulars[$unit->id][$role->value] ?? null);
            $titularOf[$unit->id] = ['name' => $titular, 'missing' => $role !== null && $titular === null && $unit->is_active];

            if (! $unit->is_active) {
                $inactive++;
            }

            if ($unit->kind === OrgUnitKind::DepartamentoAcademico && $titularOf[$unit->id]['missing']) {
                $headless++;
            }
        }

        $node = function (OrgUnit $unit, array $path) use (&$node, $childrenOf, $titularOf, $people, $withInactive): OrgChartNode {
            $path[$unit->id] = true;
            $children = [];

            foreach ($childrenOf[$unit->id] ?? [] as $child) {
                if (($withInactive || $child->is_active) && ! isset($path[$child->id])) {
                    $children[] = $node($child, $path);
                }
            }

            return new OrgChartNode(
                id: $unit->id,
                code: $unit->code,
                name: $unit->name,
                kind: $unit->kind,
                active: $unit->is_active,
                locked: $unit->kind === OrgUnitKind::DepartamentoAcademico,
                titular: $titularOf[$unit->id]['name'],
                missingTitular: $titularOf[$unit->id]['missing'],
                people: $people[$unit->id] ?? 0,
                children: $children,
            );
        };

        $roots = [];

        foreach ($units as $unit) {
            if ($unit->parent_id === null && ($withInactive || $unit->is_active)) {
                $roots[] = $node($unit, []);
            }
        }

        return new OrgChart($roots, $inactive, $headless);
    }

    /**
     * El panel de una unidad sin borrar; el id llega del navegador (KeyLookup).
     *
     * @throws ModelNotFoundException<OrgUnit>
     */
    public function detail(string $id): OrgUnitDetail
    {
        $unit = KeyLookup::findOrFail(OrgUnit::query(), $id);
        $units = $this->units();
        $childrenOf = $this->childrenOf($units);
        $byId = $units->keyBy('id');

        $path = [];
        $seen = [$unit->id => true];
        $parentId = $unit->parent_id;

        while ($parentId !== null && isset($byId[$parentId]) && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            array_unshift($path, $byId[$parentId]->name);
            $parentId = $byId[$parentId]->parent_id;
        }

        [$titular, $titularNote] = $this->titularOf($unit);
        $assignments = RoleAssignment::query()->notEnded()->where('org_unit_id', $unit->id)->with('user:id,name')->orderBy('valid_from')->orderBy('id')->get();
        $labels = $this->labels->describe($assignments);
        $today = today()->toImmutable();
        $appointRoles = $this->rules->rolesForUnit($unit);

        return new OrgUnitDetail(
            unit: $unit,
            path: $path,
            titular: $titular,
            titularNote: $titularNote,
            assignments: $assignments->map(fn (RoleAssignment $assignment): array => [
                'id' => $assignment->id,
                'name' => $assignment->user->name,
                'userId' => $assignment->user_id,
                'summary' => $labels[$assignment->id]->summary,
                'status' => AssignmentStatus::of($assignment, $today),
                'from' => $assignment->valid_from,
                'to' => $assignment->valid_to,
                'endBlocker' => $this->rules->endBlocker($assignment),
                'earliestLastDay' => $this->rules->earliestLastDay($assignment),
            ])->all(),
            people: $this->peopleCounts($units, $childrenOf, $this->currentHolders())[$unit->id] ?? 0,
            children: $childrenOf[$unit->id] ?? [],
            lockedReason: $this->unitRules->lockedReason($unit),
            moveBlocker: $this->unitRules->moveBlocker($unit),
            deactivationBlockers: $this->unitRules->deactivationBlockers($unit),
            activationBlocker: $this->unitRules->activationBlocker($unit),
            childBlocker: $this->unitRules->parentProblem($unit),
            appointRoles: $appointRoles,
            appointBlocker: $this->appointBlocker($unit, $appointRoles),
        );
    }

    /**
     * Por qué no se nombra ningún puesto sobre la unidad: ninguno la admite o está inactiva; nulo si sí.
     *
     * @param  list<Role>|null  $roles  los puestos de la unidad, si ya se calcularon
     */
    public function appointBlocker(OrgUnit $unit, ?array $roles = null): ?string
    {
        return match (true) {
            ($roles ?? $this->rules->rolesForUnit($unit)) === [] => 'Ningún puesto se nombra sobre esta unidad.',
            ! $unit->is_active => "{$unit->name} está inactiva.",
            default => null,
        };
    }

    /**
     * Un nombramiento sin terminar de esa unidad, para terminarlo; el de otra unidad, uno ya terminado o un id que no
     * es uuid no existe.
     *
     * @throws ModelNotFoundException<RoleAssignment>
     */
    public function assignmentOf(OrgUnit $unit, string $id): RoleAssignment
    {
        return KeyLookup::findOrFail(RoleAssignment::query()->notEnded()->where('org_unit_id', $unit->id), $id);
    }

    /**
     * Las madres que se ofrecen al dar de alta o mover una unidad: las activas que no son departamento académico, sin
     * `$moving` ni su subárbol, en orden de árbol y con «└ » por nivel.
     *
     * @return list<array{id: string, name: string}>
     */
    public function parentOptions(?OrgUnit $moving = null): array
    {
        $units = $this->units();
        $childrenOf = $this->childrenOf($units);
        $options = [];

        $walk = function (OrgUnit $unit, int $depth, array $path) use (&$walk, &$options, $childrenOf, $moving): void {
            if (! $unit->is_active || $unit->kind === OrgUnitKind::DepartamentoAcademico || $unit->id === $moving?->id || isset($path[$unit->id])) {
                return;
            }

            $path[$unit->id] = true;
            $options[] = ['id' => $unit->id, 'name' => ($depth === 0 ? '' : str_repeat(self::INDENT, $depth - 1).'└ ').$unit->name];

            foreach ($childrenOf[$unit->id] ?? [] as $child) {
                $walk($child, $depth + 1, $path);
            }
        };

        foreach ($units as $unit) {
            if ($unit->parent_id === null) {
                $walk($unit, 0, []);
            }
        }

        return $options;
    }

    /**
     * Una unidad sin borrar; el id llega del navegador.
     *
     * @throws ModelNotFoundException<OrgUnit>
     */
    public function findOrFail(string $id): OrgUnit
    {
        return KeyLookup::findOrFail(OrgUnit::query(), $id);
    }

    /**
     * Una unidad, borradas incluidas, para que las Actions respondan en español si ya no sirve; nula si el id no es de
     * una.
     */
    public function unit(?string $id): ?OrgUnit
    {
        return $id === null || $id === '' ? null : KeyLookup::find(OrgUnit::withTrashed(), $id);
    }

    /**
     * La raíz sin borrar (la Dirección).
     */
    public function rootId(): string
    {
        return OrgUnit::query()->whereNull('parent_id')->orderBy('created_at')->value('id');
    }

    /**
     * @return Collection<int, OrgUnit> sin borrar, por `sort` y nombre
     */
    private function units(): Collection
    {
        return OrgUnit::query()
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'name', 'kind', 'sort', 'is_active']);
    }

    /**
     * @param  Collection<int, OrgUnit>  $units
     * @return array<string, list<OrgUnit>> id de la madre → hijas en orden
     */
    private function childrenOf(Collection $units): array
    {
        $children = [];

        foreach ($units as $unit) {
            if ($unit->parent_id !== null) {
                $children[$unit->parent_id][] = $unit;
            }
        }

        return $children;
    }

    /**
     * El nombre de quien ocupa hoy cada puesto exclusivo, por unidad y puesto (una consulta).
     *
     * @return array<string, array<string, string>>
     */
    private function currentTitulars(): array
    {
        $exclusive = array_map(fn (Role $role): string => $role->value, array_filter(Role::cases(), fn (Role $role): bool => $role->isExclusive()));
        $titulars = [];

        $rows = RoleAssignment::query()
            ->current()
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereIn('role_assignments.role', $exclusive)
            ->whereNotNull('role_assignments.org_unit_id')
            ->orderBy('role_assignments.valid_from')
            ->toBase()
            ->get(['role_assignments.org_unit_id', 'role_assignments.role', 'users.name']);

        foreach ($rows as $row) {
            $titulars[$row->org_unit_id][$row->role] ??= $row->name;
        }

        return $titulars;
    }

    /**
     * Los usuarios con nombramiento vigente en cada unidad (una consulta).
     *
     * @return array<string, list<string>>
     */
    private function currentHolders(): array
    {
        $holders = [];

        $rows = RoleAssignment::query()
            ->current()
            ->whereNotNull('org_unit_id')
            ->distinct()
            ->toBase()
            ->get(['org_unit_id', 'user_id']);

        foreach ($rows as $row) {
            $holders[$row->org_unit_id][] = $row->user_id;
        }

        return $holders;
    }

    /**
     * Cuántos usuarios distintos tienen adscripción o nombramiento vigente en cada unidad o en su subárbol.
     *
     * @param  Collection<int, OrgUnit>  $units
     * @param  array<string, list<OrgUnit>>  $childrenOf
     * @param  array<string, list<string>>  $holders
     * @return array<string, int>
     */
    private function peopleCounts(Collection $units, array $childrenOf, array $holders): array
    {
        $own = [];

        foreach ([$holders, $this->adscriptions->userIdsByUnit()] as $byUnit) {
            foreach ($byUnit as $unitId => $userIds) {
                foreach ($userIds as $userId) {
                    $own[$unitId][$userId] = true;
                }
            }
        }

        $sets = [];

        $collect = function (string $id, array $path) use (&$collect, &$sets, $own, $childrenOf): array {
            if (isset($sets[$id])) {
                return $sets[$id];
            }

            $path[$id] = true;
            $set = $own[$id] ?? [];

            foreach ($childrenOf[$id] ?? [] as $child) {
                if (! isset($path[$child->id])) {
                    $set += $collect($child->id, $path);
                }
            }

            return $sets[$id] = $set;
        };

        $counts = [];

        foreach ($units as $unit) {
            $counts[$unit->id] = count($collect($unit->id, []));
        }

        return $counts;
    }

    /**
     * El titular vigente de la unidad o por qué no lo tiene.
     *
     * @return array{0: array{name: string, userId: string, summary: string, from: CarbonImmutable}|null, 1: string|null}
     */
    private function titularOf(OrgUnit $unit): array
    {
        $role = $this->rules->titularRoleOf($unit);

        if ($role === null) {
            return [null, $unit->kind === OrgUnitKind::Direccion
                ? 'La Dirección no tiene un puesto en Nexus.'
                : 'Esta unidad todavía no tiene un puesto de titular en Nexus.'];
        }

        $assignment = RoleAssignment::query()->current()->where('role', $role)->where('org_unit_id', $unit->id)->with('user:id,name')->orderBy('valid_from')->first();

        if ($assignment === null) {
            return [null, $role === Role::JefeDepartamento
                ? 'Sin jefe vigente. El jefe se nombra en la ficha del docente, en «Personas y puestos» o aquí.'
                : 'Sin titular vigente.'];
        }

        return [[
            'name' => $assignment->user->name,
            'userId' => $assignment->user_id,
            'summary' => $this->labels->one($assignment)->summary,
            'from' => $assignment->valid_from,
        ], null];
    }
}
