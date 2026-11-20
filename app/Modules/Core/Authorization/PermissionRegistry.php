<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use LogicException;

/**
 * Catálogo de permisos y de lo que cada puesto tiene concedido **de fábrica**: lo que los módulos declaran en
 * permissions(), grants(), adminDenies() y adminOnly() de su provider. Se valida al armarlo, así que un catálogo
 * malformado falla al arrancar.
 *
 * El admin tiene implícitamente todo lo declarado menos adminDenies. Lo que un puesto tiene hoy lo decide GrantTable
 * (CF-01, roles editables): sus roles de fábrica siguen lo que aquí se concede mientras nadie los edite (CF-02). Los
 * candados (adminOnly, CF-03) no se conceden a ningún puesto, salvo la excepción de fábrica que un módulo declara en
 * factoryLockExceptions(): un candado de lectura que el código le da a un puesto que lee en todo el Tec (desde el 20 de
 * noviembre de 2026, ver Personas y puestos, el Organigrama y la Bitácora al directivo, AZ-03). Cada excepción debe estar
 * también en grants() y todo candado en grants() debe ser excepción, así que nada se cuela sin las dos. A un puesto que
 * lee en todo el Tec solo se le conceden permisos de lectura. Core lo arma una vez y lo comparte entre peticiones
 * (Octane): es inmutable, no guarda nada del usuario ni de la petición.
 */
final class PermissionRegistry
{
    /** @var array<string, Permission> */
    private array $byKey = [];

    /** @var array<string, array<string, true>> */
    private array $granted = [];

    /** @var array<string, true> */
    private array $denied = [];

    /** @var array<string, true> */
    private array $locked = [];

    /** @var array<string, array<string, true>> por `Role->value`: los candados que se le conceden de fábrica (CF-03) */
    private array $lockExceptions = [];

    private readonly string $fingerprint;

    /**
     * @param  list<class-string<Permission>>  $permissionEnums
     * @param  array<string, list<Permission>>  $grants  llave: `Role->value`
     * @param  list<Permission>  $adminDenies
     * @param  list<Permission>  $adminOnly
     * @param  array<string, list<Permission>>  $lockExceptions  llave: `Role->value`
     */
    private function __construct(array $permissionEnums, array $grants, array $adminDenies, array $adminOnly, array $lockExceptions)
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
            $position = Role::tryFrom((string) $role)
                ?? throw new LogicException("Se conceden permisos a un puesto que no existe: «{$role}».");

            foreach ($permissions as $permission) {
                $declared = $this->declared($permission);

                if ($position->readsWholeTec() && ! $declared->isReadOnly()) {
                    throw new LogicException("El permiso «{$declared->value}» no es de lectura: no se concede a «{$role}», que lee en todo el Tec (AZ-03).");
                }

                $this->granted[$role][$declared->value] = true;
            }
        }

        foreach ($adminDenies as $permission) {
            $this->denied[$this->declared($permission)->value] = true;
        }

        foreach ($adminOnly as $permission) {
            $key = $this->declared($permission)->value;

            if (isset($this->denied[$key])) {
                throw new LogicException("El permiso «{$key}» es candado (CF-03) y excepción del admin a la vez: nadie lo tendría.");
            }

            $this->locked[$key] = true;
        }

        foreach ($lockExceptions as $role => $permissions) {
            $position = Role::tryFrom((string) $role)
                ?? throw new LogicException("Se exceptúa un candado para un puesto que no existe: «{$role}».");

            foreach ($permissions as $permission) {
                $key = $this->declared($permission)->value;

                match (true) {
                    ! isset($this->granted[$role][$key]) => throw new LogicException("La excepción de candado «{$key}» de «{$role}» no está concedida en grants()."),
                    ! isset($this->locked[$key]) => throw new LogicException("La excepción de candado «{$key}» de «{$role}» no es candado (CF-03)."),
                    ! $permission->isReadOnly() => throw new LogicException("La excepción de candado «{$key}» no es de lectura."),
                    ! $position->readsWholeTec() => throw new LogicException("La excepción de candado «{$key}» es para «{$role}», que no lee en todo el Tec (AZ-03)."),
                    default => $this->lockExceptions[$role][$key] = true,
                };
            }
        }

        foreach ($this->granted as $role => $keys) {
            foreach (array_keys($keys) as $key) {
                if (isset($this->locked[$key]) && ! isset($this->lockExceptions[$role][$key])) {
                    throw new LogicException("El permiso «{$key}» es candado (CF-03): solo lo tiene el admin y no se concede a «{$role}» en grants().");
                }
            }
        }

        $this->fingerprint = hash('xxh128', (string) json_encode([
            'grants' => $this->matrix(),
            'permissions' => array_map(fn (Permission $permission): array => [
                array_map(fn (Dimension $dimension): string => $dimension->value, $permission->dimensions()),
                $permission->isPersonal(),
                $permission->isReadOnly(),
            ], $this->byKey),
            'admin_only' => $this->adminOnly(),
        ]));
    }

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $enums = [];
        $grants = [];
        $adminDenies = [];
        $adminOnly = [];
        $lockExceptions = [];

        foreach ($modules as $module) {
            array_push($enums, ...$module->permissions());
            array_push($adminDenies, ...$module->adminDenies());
            array_push($adminOnly, ...$module->adminOnly());

            foreach ($module->grants() as $role => $permissions) {
                $grants[$role] = [...($grants[$role] ?? []), ...$permissions];
            }

            foreach ($module->factoryLockExceptions() as $role => $permissions) {
                $lockExceptions[$role] = [...($lockExceptions[$role] ?? []), ...$permissions];
            }
        }

        return new self($enums, $grants, $adminDenies, $adminOnly, $lockExceptions);
    }

    /**
     * @param  list<class-string<Permission>>  $permissionEnums
     * @param  array<string, list<Permission>>  $grants  llave: `Role->value`
     * @param  list<Permission>  $adminDenies
     * @param  list<Permission>  $adminOnly
     * @param  array<string, list<Permission>>  $lockExceptions  llave: `Role->value`
     */
    public static function fromDeclarations(array $permissionEnums, array $grants, array $adminDenies, array $adminOnly = [], array $lockExceptions = []): self
    {
        return new self($permissionEnums, $grants, $adminDenies, $adminOnly, $lockExceptions);
    }

    /**
     * Si el puesto tiene concedido el permiso de fábrica (en grants()); el admin, todo menos adminDenies. Lo que tiene
     * hoy lo dice GrantTable. Lanza LogicException (cierra) si el permiso no está declarado.
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
     * Los puestos con el permiso concedido de fábrica, sin el admin implícito. Lanza LogicException (cierra) si el
     * permiso no está declarado.
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
     * AZ-S16: los permisos que declaran a qué unidades aplican, agrupados por UnitApplicability::key(); grupos y claves
     * ordenados. Los que aplican a cualquier unidad no salen. Lo congela `matrix.json` junto con las concesiones.
     *
     * @return array<string, list<string>>
     */
    public function applicability(): array
    {
        $groups = [];

        foreach ($this->byKey as $key => $permission) {
            $applicability = $permission->appliesTo();

            if ($applicability !== null) {
                $groups[$applicability->key()][] = $key;
            }
        }

        $groups = array_map(function (array $keys): array {
            sort($keys);

            return $keys;
        }, $groups);
        ksort($groups);

        return $groups;
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

    /**
     * CF-03: si el permiso es candado (solo del admin, no se da a otro puesto). Lanza LogicException si no está
     * declarado.
     */
    public function isAdminOnly(Permission $permission): bool
    {
        return isset($this->locked[$this->declared($permission)->value]);
    }

    /**
     * CF-03, la excepción de fábrica: si el permiso es un candado que el código le concede de fábrica a ese puesto
     * (factoryLockExceptions()). Lanza LogicException si no está declarado.
     */
    public function isFactoryLockException(Role $role, Permission $permission): bool
    {
        return isset($this->lockExceptions[$role->value][$this->declared($permission)->value]);
    }

    /**
     * Las claves candado (CF-03), ordenadas.
     *
     * @return list<string>
     */
    public function adminOnly(): array
    {
        $keys = array_keys($this->locked);
        sort($keys);

        return $keys;
    }

    /**
     * El permiso declarado con esa clave, o null: para leer las claves que guarda la base (roles y permisos sueltos).
     */
    public function forKey(string $key): ?Permission
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * La clave del permiso; lanza LogicException (cierra) si no está declarado.
     */
    public function keyOf(Permission $permission): string
    {
        return (string) $this->declared($permission)->value;
    }

    /**
     * Huella de lo que el código decide sobre las concesiones: lo de fábrica por puesto, las dimensiones, si es
     * personal y si es de lectura (AZ-03) cada permiso declarado, y los candados. GrantTable la pone en su llave de
     * caché: un despliegue que cambie algo de eso no lee el mapa que armó el código anterior.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * El permiso declarado que corresponde al dado. Falla (cierra) si ningún módulo lo declaró, o si
     * es un caso de otro enum que solo repite una clave declarada.
     */
    private function declared(Permission $permission): Permission
    {
        $declared = $this->byKey[$permission->value]
            ?? throw new LogicException("El permiso «{$permission->value}» no lo declaró ningún módulo.");

        if ($declared !== $permission) {
            throw new LogicException("El permiso «{$permission->value}» es de ".$permission::class.', que ningún módulo declaró; la clave la declara '.$declared::class.'.');
        }

        return $declared;
    }
}
