<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\UnitApplicability;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;

/**
 * El catálogo de permisos para «Puestos y permisos» (CF-S1): cada permiso declarado, en el orden de declaración, con
 * su módulo, su grupo en la pantalla, su etiqueta, sus dimensiones (y cómo se dicen), si es personal, a qué unidades
 * aplica (AZ-02, de solo lectura, también dicho en una línea), si es candado (CF-03) o excepción del admin, qué puestos
 * lo tienen hoy y por qué (los orígenes de PositionsQuery, en texto y como dato), y cuántas personas distintas lo
 * tienen hoy por alguno de esos puestos.
 *
 * Los motivos por los que un puesto no puede recibir un permiso (problemsFor(), grantProblems()) son los de
 * PermissionGrantRules, los mismos que da la Action al bloquear (CF-S1, CF-03, CF-04).
 */
final class PermissionCatalogQuery
{
    /**
     * Los grupos de la pantalla, en orden: uno por módulo, y Core partido en dos para que se lea.
     *
     * @var array<string, string>
     */
    public const array GROUPS = [
        'core_institution' => 'Institución',
        'core_accounts' => 'Cuentas y configuración',
        'academic' => 'Catálogos académicos',
        'facilities' => 'Edificios y salones',
        'faculty' => 'Docentes',
        'students' => 'Alumnos',
        'imports' => 'Importaciones',
        'catalog_validation' => 'Validación de catálogos',
        'academic_load' => 'Carga académica',
        'scheduling' => 'Horarios',
        'staffing' => 'Asignación docente',
    ];

    /**
     * Los recursos de Core que van en «Institución»; los demás, en «Cuentas y configuración».
     */
    private const array CORE_INSTITUTION = ['campus', 'modality', 'term', 'org_unit', 'navigation'];

    public const string ADMIN_DENIED_PROBLEM = 'Es una excepción de Administración: sigue en código.';

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PositionsQuery $positions,
        private readonly PermissionGrantRules $grantRules,
    ) {}

    /**
     * `$positions` y `$people` son PositionsQuery::all() y PositionsQuery::peopleByRoleSet(), si ya se leyeron.
     *
     * @param  list<array<string, mixed>>|null  $positions
     * @param  array<string, int>|null  $people
     * @return list<array{
     *     key: string,
     *     label: string,
     *     module: string,
     *     group: string,
     *     group_label: string,
     *     dimensions: list<Dimension>,
     *     scope_label: string,
     *     personal: bool,
     *     applies_to: UnitApplicability|null,
     *     applies_label: string|null,
     *     admin_only: bool,
     *     admin_denied: bool,
     *     positions: list<array{role: Role, origins: list<string>, sources: list<array{kind: string, name: string|null}>}>,
     *     people: int,
     * }>
     */
    public function all(?array $positions = null, ?array $people = null): array
    {
        $byKey = [];
        $people ??= $this->positions->peopleByRoleSet();

        foreach ($positions ?? $this->positions->all($people) as $position) {
            foreach ($position['effective'] as $permission) {
                $byKey[$permission['key']][] = ['role' => $position['role'], 'origins' => $permission['origins'], 'sources' => $permission['sources']];
            }
        }

        $denied = $this->registry->adminDenials();

        return array_map(function (Permission $permission) use ($byKey, $people, $denied): array {
            $key = (string) $permission->value;
            $holders = $byKey[$key] ?? [];
            $roles = array_map(fn (array $holder): string => $holder['role']->value, $holders);
            $group = self::groupOf($key);

            return [
                'key' => $key,
                'label' => $permission->label(),
                'module' => explode('.', $key)[0],
                'group' => $group,
                'group_label' => self::GROUPS[$group] ?? $group,
                'dimensions' => $permission->dimensions(),
                'scope_label' => $this->scopeLabel($permission),
                'personal' => $permission->isPersonal(),
                'applies_to' => $permission->appliesTo(),
                'applies_label' => $this->appliesLabel($permission->appliesTo()),
                'admin_only' => $this->registry->isAdminOnly($permission),
                'admin_denied' => in_array($key, $denied, true),
                'positions' => $holders,
                'people' => array_sum(array_filter($people, fn (string $set): bool => array_intersect(explode(',', $set), $roles) !== [], ARRAY_FILTER_USE_KEY)),
            ];
        }, $this->registry->permissions());
    }

    /**
     * El permiso declarado con esa clave, o null.
     */
    public function find(string $key): ?Permission
    {
        return $this->registry->forKey($key);
    }

    /**
     * Por qué cada puesto no puede recibir el permiso, o null si puede (lo tenga o no hoy): para el admin, si es una de
     * sus excepciones; para los demás, PermissionGrantRules::grantProblem(). En el orden del enum.
     *
     * @return array<string, string|null> por `Role->value`
     */
    public function problemsFor(string $key): array
    {
        $permission = $this->registry->forKey($key);
        $problems = [];

        foreach (Role::cases() as $role) {
            $problems[$role->value] = match (true) {
                $permission === null => "El permiso «{$key}» no existe.",
                $role === Role::Admin => in_array($key, $this->registry->adminDenials(), true) ? self::ADMIN_DENIED_PROBLEM : null,
                default => $this->grantRules->grantProblem($role, $permission),
            };
        }

        return $problems;
    }

    /**
     * Por qué el puesto no puede recibir cada permiso declarado, o null si puede (PermissionGrantRules::grantProblem()).
     *
     * @return array<string, string|null> por clave
     */
    public function grantProblems(Role $role): array
    {
        $problems = [];

        foreach ($this->registry->permissions() as $permission) {
            $problems[(string) $permission->value] = $this->grantRules->grantProblem($role, $permission);
        }

        return $problems;
    }

    /**
     * El grupo de la pantalla de una clave: su módulo, y Core partido en dos (GROUPS).
     */
    public static function groupOf(string $key): string
    {
        [$module, $resource] = [...explode('.', $key), ''];

        if ($module !== 'core') {
            return $module;
        }

        return in_array($resource, self::CORE_INSTITUTION, true) ? 'core_institution' : 'core_accounts';
    }

    /**
     * Las filas (con `group` y `group_label`) agrupadas en el orden de GROUPS; un grupo que no está ahí va al final.
     * Sin los grupos vacíos.
     *
     * @template TRow of array{group: string, group_label: string}
     *
     * @param  list<TRow>  $rows
     * @return list<array{id: string, label: string, rows: list<TRow>}>
     */
    public static function grouped(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['group']] ??= ['id' => $row['group'], 'label' => $row['group_label'], 'rows' => []];
            $groups[$row['group']]['rows'][] = $row;
        }

        $order = array_flip(array_keys(self::GROUPS));
        uksort($groups, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return array_values($groups);
    }

    /**
     * Cómo se acota el permiso, en una línea: por sus dimensiones, en el orden modalidad, campus, departamento y
     * carrera; o si es personal o sin alcance.
     */
    private function scopeLabel(Permission $permission): string
    {
        $names = [Dimension::Modality->value => 'modalidad', Dimension::Campus->value => 'campus', Dimension::Unit->value => 'departamento', Dimension::Career->value => 'carrera'];
        $dimensions = array_map(fn (Dimension $dimension): string => $dimension->value, $permission->dimensions());
        $words = array_values(array_intersect_key($names, array_flip($dimensions)));

        if ($words === []) {
            return $permission->isPersonal() ? 'Personal: solo sobre lo propio' : 'Sin alcance: vale en todo Nexus';
        }

        return 'Se acota por '.$this->sentence($words);
    }

    /**
     * A qué unidades aplica, en una línea (AZ-02, AZ-S16): los tipos y luego las unidades por su clave.
     */
    private function appliesLabel(?UnitApplicability $applies): ?string
    {
        if ($applies === null) {
            return null;
        }

        $parts = [
            ...array_map(fn (OrgUnitKind $kind): string => $kind === OrgUnitKind::DepartamentoAcademico ? 'departamentos académicos' : mb_strtolower($kind->label()), $applies->kinds),
            ...array_map(fn (string $code): string => match ($code) {
                OrgTree::ACADEMIC => 'la Subdirección Académica',
                OrgTree::SCHOOL_SERVICES => 'Servicios Escolares',
                default => $code,
            }, $applies->codes),
        ];

        return 'Solo en '.$this->sentence($parts);
    }

    /**
     * @param  list<string>  $words
     */
    private function sentence(array $words): string
    {
        return count($words) === 1 ? $words[0] : implode(', ', array_slice($words, 0, -1)).' y '.end($words);
    }
}
