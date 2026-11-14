<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cada puesto tiene concedido hoy (roles editables, CF-01; adenda del 14 de noviembre de 2026 a ADR-0018).
 * Actor y RecipientsQuery le preguntan a esta clase, no al registro.
 *
 * - El admin, lo del registro: todo lo declarado menos adminDenies() (CF-S1).
 * - Los demás puestos, la unión de sus permisos sueltos (`position_permissions`) y de sus roles activos y no borrados
 *   (`position_permission_sets`). Un rol que sigue al código (`follows_factory`) aporta lo que el registro concede de
 *   fábrica a su `factory_role` (CF-02); uno que no, sus filas de `permission_set_permissions`.
 * - Lo que la base guarde y las reglas no permitan (un candado, CF-03, o algo que ampliaría el alcance, CF-04) no
 *   cuenta, igual que una clave que ningún módulo declara: las reglas valen aunque algo llegue por otro camino.
 *
 * Caché: el mapa se guarda con la llave `authz:grants:v{FORMAT}:{versión}:{huella}`. La versión es la fila de
 * `authz_grants_version`, que suben triggers de sentencia en las cuatro tablas, así que ningún camino de escritura la
 * olvida; se lee una vez por petición, antes que las tablas, de modo que un mapa nunca es más viejo que su llave. La
 * huella (fingerprint()) sale del código: lo de fábrica, las dimensiones de cada permiso, los candados y lo que acota
 * cada puesto; un despliegue que los cambie no lee lo que armó el anterior. Como AuthorizationSnapshots, el mapa se
 * escribe en la caché solo cuando la transacción en curso se confirma: una revertida deshace la subida de la versión y
 * la siguiente reusaría el número. Lo cacheado se lee estricto y, si no tiene la forma, se vuelve a armar.
 *
 * Se registra como `scoped` (Octane): la versión y el mapa se memorizan en la instancia, y la siguiente petición los
 * vuelve a leer. Una Action que cambie concesiones a media petición llama a refresh().
 */
final class GrantTable
{
    /**
     * Versión de la forma del mapa en la llave. Súbela si cambia lo que se guarda.
     */
    public const int FORMAT = 1;

    private ?int $version = null;

    /** @var array<string, array<string, true>>|null por `Role->value`, sin el admin */
    private ?array $map = null;

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionGrantRules $rules,
        private readonly Repository $cache,
    ) {}

    /**
     * Si el puesto tiene hoy el permiso; el admin, todo menos adminDenies. Lanza LogicException (cierra) si el permiso
     * no está declarado.
     */
    public function grants(Role $role, Permission $permission): bool
    {
        if ($role === Role::Admin) {
            return $this->registry->grants($role, $permission);
        }

        return isset($this->map()[$role->value][$this->registry->keyOf($permission)]);
    }

    /**
     * Los puestos que tienen hoy el permiso, sin el admin implícito, en el orden del enum. Lanza LogicException
     * (cierra) si el permiso no está declarado.
     *
     * @return list<Role>
     */
    public function rolesGranting(Permission $permission): array
    {
        $key = $this->registry->keyOf($permission);
        $map = $this->map();

        return array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $role !== Role::Admin && isset($map[$role->value][$key]),
        ));
    }

    /**
     * Lo que tiene hoy cada puesto, sin el admin, con la forma de PermissionRegistry::matrix(): puestos y claves
     * ordenados, sin los puestos que no tienen nada.
     *
     * @return array<string, list<string>>
     */
    public function matrix(): array
    {
        $matrix = array_filter(array_map(function (array $keys): array {
            $keys = array_keys($keys);
            sort($keys);

            return $keys;
        }, $this->map()));
        ksort($matrix);

        return $matrix;
    }

    /**
     * La versión de las concesiones de esta petición (`authz_grants_version`), leída una vez.
     */
    public function version(): int
    {
        return $this->version ??= (int) DB::table('authz_grants_version')->value('version');
    }

    public function cacheKey(): string
    {
        return 'authz:grants:v'.self::FORMAT.':'.$this->version().':'.$this->fingerprint();
    }

    /**
     * Vuelve a leer la versión y el mapa en la siguiente pregunta. La llama la Action que cambia concesiones.
     */
    public function refresh(): void
    {
        $this->version = null;
        $this->map = null;
    }

    /**
     * La huella del código (ver el docblock de la clase): la del registro y lo que acota cada puesto (CF-04).
     */
    private function fingerprint(): string
    {
        $bounded = array_map(
            fn (Role $role): string => $role->value.'='.implode(',', array_map(fn (Dimension $dimension): string => $dimension->value, $role->boundedDimensions())),
            Role::cases(),
        );

        return hash('xxh128', $this->registry->fingerprint().'|'.implode(';', $bounded));
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $key = $this->cacheKey();
        $cached = $this->fromCache($this->cache->get($key));

        if ($cached !== null) {
            return $this->map = $cached;
        }

        $map = $this->load();
        $data = array_map(fn (array $keys): array => array_keys($keys), $map);

        DB::afterCommit(fn () => $this->cache->put($key, $data, now()->addDay()));

        return $this->map = $map;
    }

    /**
     * El mapa guardado, si tiene la forma: `Role->value` => lista de claves. Si no, null.
     *
     * @return array<string, array<string, true>>|null
     */
    private function fromCache(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        $map = [];

        foreach ($cached as $role => $keys) {
            if (! is_string($role) || Role::tryFrom($role) === null || ! is_array($keys) || ! array_is_list($keys)) {
                return null;
            }

            foreach ($keys as $permission) {
                if (! is_string($permission)) {
                    return null;
                }

                $map[$role][$permission] = true;
            }
        }

        return $map;
    }

    /**
     * Arma el mapa desde la base, en tres consultas: los roles activos y no borrados de cada puesto, las filas de los
     * que no siguen al código y los permisos sueltos.
     *
     * @return array<string, array<string, true>>
     */
    private function load(): array
    {
        $factory = $this->registry->matrix();
        $granted = [];

        $sets = DB::table('position_permission_sets')
            ->join('permission_sets', 'permission_sets.id', '=', 'position_permission_sets.permission_set_id')
            ->where('permission_sets.is_active', true)
            ->whereNull('permission_sets.deleted_at')
            ->get(['position_permission_sets.role', 'permission_sets.id', 'permission_sets.factory_role', 'permission_sets.follows_factory']);

        $own = DB::table('permission_set_permissions')
            ->whereIn('permission_set_id', $sets->where('follows_factory', false)->pluck('id')->unique()->values()->all())
            ->get(['permission_set_id', 'permission'])
            ->groupBy('permission_set_id');

        foreach ($sets as $set) {
            $keys = $set->follows_factory
                ? ($factory[$set->factory_role] ?? [])
                : ($own->get($set->id)?->pluck('permission')->all() ?? []);

            foreach ($keys as $key) {
                $granted[$set->role][$key] = true;
            }
        }

        foreach (DB::table('position_permissions')->get(['role', 'permission']) as $row) {
            $granted[$row->role][$row->permission] = true;
        }

        $map = [];

        foreach (Role::cases() as $role) {
            if ($role === Role::Admin) {
                continue;
            }

            foreach (array_keys($granted[$role->value] ?? []) as $key) {
                $permission = $this->registry->forKey($key);

                if ($permission !== null && $this->rules->grantProblem($role, $permission) === null) {
                    $map[$role->value][$key] = true;
                }
            }
        }

        return $map;
    }
}
