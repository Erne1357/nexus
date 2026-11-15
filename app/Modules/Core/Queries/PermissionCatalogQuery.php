<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\UnitApplicability;
use App\Modules\Core\Enums\Role;

/**
 * El catálogo de permisos para «Puestos y permisos» (CF-S1): cada permiso declarado, en el orden de declaración, con
 * su módulo, su etiqueta, sus dimensiones, si es personal, a qué unidades aplica (AZ-02, de solo lectura), si es
 * candado (CF-03) o excepción del admin, qué puestos lo tienen hoy y por qué (los orígenes de PositionsQuery), y
 * cuántas personas distintas lo tienen hoy por alguno de esos puestos.
 *
 * Para el drawer de agregar (confirmado el 15 de noviembre de 2026): `institution_wide` dice si el permiso podría valer
 * en todo el Tec (de gestión, sin unidad ni carrera), e `institution_wide_for` los puestos editables a los que se
 * puede dar y para los que valdría en todo el Tec (PermissionGrantRules::isInstitutionWide()): ahí la pantalla avisa «Valdrá en todo
 * el Tec». `blocked_for` da, por puesto editable, el motivo por el que no se le puede dar (CF-03, CF-04).
 */
final class PermissionCatalogQuery
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PositionsQuery $positions,
        private readonly PermissionGrantRules $rules,
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
     *     institution_wide: bool,
     *     institution_wide_for: list<Role>,
     *     blocked_for: array<string, string>,
     * }>
     */
    public function all(): array
    {
        $byKey = [];
        $editable = [];
        $people = $this->positions->peopleByRoleSet();

        foreach ($this->positions->all($people) as $position) {
            if ($position['locked'] === null) {
                $editable[] = $position['role'];
            }

            foreach ($position['effective'] as $permission) {
                $byKey[$permission['key']][] = ['role' => $position['role'], 'origins' => $permission['origins']];
            }
        }

        $denied = $this->registry->adminDenials();

        return array_map(function (Permission $permission) use ($byKey, $people, $denied, $editable): array {
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
                'institution_wide' => PermissionGrantRules::declaresNoUnitNorCareer($permission),
                'institution_wide_for' => array_values(array_filter(
                    $editable,
                    fn (Role $role): bool => $this->rules->isInstitutionWide($role, $permission) && $this->rules->grantProblem($role, $permission) === null,
                )),
                'blocked_for' => array_filter(array_combine(
                    array_map(fn (Role $role): string => $role->value, $editable),
                    array_map(fn (Role $role): ?string => $this->rules->grantProblem($role, $permission), $editable),
                ), fn (?string $problem): bool => $problem !== null),
            ];
        }, $this->registry->permissions());
    }
}
