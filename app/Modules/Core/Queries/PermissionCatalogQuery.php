<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\UnitApplicability;
use App\Modules\Core\Enums\Role;

/**
 * El catálogo de permisos para «Puestos y permisos» (CF-S1): cada permiso declarado, en el orden de declaración, con
 * su módulo, su etiqueta, sus dimensiones, si es personal, a qué unidades aplica (AZ-02, de solo lectura), si es
 * candado (CF-03) o excepción del admin, qué puestos lo tienen hoy y por qué (los orígenes de PositionsQuery), y
 * cuántas personas distintas lo tienen hoy por alguno de esos puestos.
 */
final class PermissionCatalogQuery
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PositionsQuery $positions,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     module: string,
     *     dimensions: list<Dimension>,
     *     personal: bool,
     *     applies_to: UnitApplicability|null,
     *     admin_only: bool,
     *     admin_denied: bool,
     *     positions: list<array{role: Role, origins: list<string>}>,
     *     people: int,
     * }>
     */
    public function all(): array
    {
        $byKey = [];
        $people = $this->positions->peopleByRoleSet();

        foreach ($this->positions->all($people) as $position) {
            foreach ($position['effective'] as $permission) {
                $byKey[$permission['key']][] = ['role' => $position['role'], 'origins' => $permission['origins']];
            }
        }

        $denied = $this->registry->adminDenials();

        return array_map(function (Permission $permission) use ($byKey, $people, $denied): array {
            $key = (string) $permission->value;
            $holders = $byKey[$key] ?? [];
            $roles = array_map(fn (array $holder): string => $holder['role']->value, $holders);

            return [
                'key' => $key,
                'label' => $permission->label(),
                'module' => explode('.', $key)[0],
                'dimensions' => $permission->dimensions(),
                'personal' => $permission->isPersonal(),
                'applies_to' => $permission->appliesTo(),
                'admin_only' => $this->registry->isAdminOnly($permission),
                'admin_denied' => in_array($key, $denied, true),
                'positions' => $holders,
                'people' => array_sum(array_filter($people, fn (string $set): bool => array_intersect(explode(',', $set), $roles) !== [], ARRAY_FILTER_USE_KEY)),
            ];
        }, $this->registry->permissions());
    }
}
