<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use LogicException;

/**
 * Catálogo de permisos y de lo que cada puesto tiene concedido: lo que los módulos declaran en
 * permissions(), grants() y adminDenies() de su provider. Se valida al armarlo, así que un
 * catálogo malformado falla al arrancar.
 *
 * El admin tiene implícitamente todo lo declarado menos adminDenies. Core lo arma una vez y lo
 * comparte entre peticiones (Octane): es inmutable, no guarda nada del usuario ni de la petición.
 */
final class PermissionRegistry
{
    /** @var array<string, Permission> */
    private array $byKey = [];

    /** @var array<string, array<string, true>> */
    private array $granted = [];

    /** @var array<string, true> */
    private array $denied = [];

    /**
     * @param  list<class-string<Permission>>  $permissionEnums
     * @param  array<string, list<Permission>>  $grants  llave: `Role->value`
     * @param  list<Permission>  $adminDenies
     */
    private function __construct(array $permissionEnums, array $grants, array $adminDenies)
    {
        foreach ($permissionEnums as $enum) {
            foreach ($enum::cases() as $permission) {
                $key = $permission->value;

                if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
                    throw new LogicException("La clave de permiso «{$key}» ({$enum}) no tiene la forma modulo.recurso.accion.");
                }

                if (isset($this->byKey[$key])) {
                    throw new LogicException("La clave de permiso «{$key}» está repetida ({$enum}).");
                }

                $this->byKey[$key] = $permission;
            }
        }

        foreach ($grants as $role => $permissions) {
            if (Role::tryFrom((string) $role) === null) {
                throw new LogicException("Se conceden permisos a un puesto que no existe: «{$role}».");
            }

            foreach ($permissions as $permission) {
                $this->granted[$role][$this->declared($permission)->value] = true;
            }
        }

        foreach ($adminDenies as $permission) {
            $this->denied[$this->declared($permission)->value] = true;
        }
    }

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $enums = [];
        $grants = [];
        $adminDenies = [];

        foreach ($modules as $module) {
            array_push($enums, ...$module->permissions());
            array_push($adminDenies, ...$module->adminDenies());

            foreach ($module->grants() as $role => $permissions) {
                $grants[$role] = [...($grants[$role] ?? []), ...$permissions];
            }
        }

        return new self($enums, $grants, $adminDenies);
    }

    /**
     * @param  list<class-string<Permission>>  $permissionEnums
     * @param  array<string, list<Permission>>  $grants  llave: `Role->value`
     * @param  list<Permission>  $adminDenies
     */
    public static function fromDeclarations(array $permissionEnums, array $grants, array $adminDenies): self
    {
        return new self($permissionEnums, $grants, $adminDenies);
    }

    /**
     * Si el puesto tiene concedido el permiso; el admin, todo menos adminDenies.
     */
    public function grants(Role $role, Permission $permission): bool
    {
        $key = $this->declared($permission)->value;

        if ($role === Role::Admin) {
            return ! isset($this->denied[$key]);
        }

        return isset($this->granted[$role->value][$key]);
    }

    /**
     * Los puestos con el permiso concedido de forma explícita, sin el admin implícito.
     *
     * @return list<Role>
     */
    public function rolesGranting(Permission $permission): array
    {
        $key = $this->declared($permission)->value;

        return array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $role !== Role::Admin && isset($this->granted[$role->value][$key]),
        ));
    }

    /**
     * Todos los permisos declarados, en el orden de declaración.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values($this->byKey);
    }

    /**
     * Las concesiones explícitas por puesto (sin el admin implícito): puestos y claves ordenados.
     *
     * @return array<string, list<string>>
     */
    public function matrix(): array
    {
        $matrix = array_map(function (array $keys): array {
            $keys = array_keys($keys);
            sort($keys);

            return $keys;
        }, $this->granted);
        ksort($matrix);

        return $matrix;
    }

    /**
     * Las claves que el admin no tiene, ordenadas.
     *
     * @return list<string>
     */
    public function adminDenials(): array
    {
        $keys = array_keys($this->denied);
        sort($keys);

        return $keys;
    }

    private function declared(Permission $permission): Permission
    {
        return $this->byKey[$permission->value]
            ?? throw new LogicException("El permiso «{$permission->value}» no lo declaró ningún módulo.");
    }
}
