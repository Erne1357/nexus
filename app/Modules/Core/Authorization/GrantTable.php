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
 * Caché: el mapa se guarda con la llave `authz:grants:v{FORMAT}:{versión}:{sello}:{huella}`. La versión y el sello son
 * la fila de `authz_grants_version`, que suben triggers de sentencia en las cuatro tablas, así que ningún camino de
 * escritura los olvida. El sello es un uuid nuevo en cada cambio: dos bases con la misma versión (otro worktree contra
 * el mismo Valkey, una base recién migrada o restaurada) no comparten llave. Se leen una vez por petición. Si el mapa
 * no está en la caché, se arma con una sola consulta que vuelve a leer la versión y el sello junto con las tres
 * tablas, así que el mapa sale de un solo estado de la base y se guarda con la llave de ese estado. La huella
 * (fingerprint()) sale del código: lo de fábrica, las dimensiones de cada permiso, los candados y lo que acota cada
 * puesto; un despliegue que los cambie no lee lo que armó el anterior. Como AuthorizationSnapshots, el mapa se escribe
 * en la caché solo cuando la transacción en curso se confirma. Lo cacheado se lee estricto y, si no tiene la forma, se
 * vuelve a armar.
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

    /** @var array{version: int, token: string}|null */
    private ?array $stamp = null;

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
        return $this->stamp()['version'];
    }

    public function cacheKey(): string
    {
        $stamp = $this->stamp();

        return 'authz:grants:v'.self::FORMAT.":{$stamp['version']}:{$stamp['token']}:".$this->fingerprint();
    }

    /**
     * Vuelve a leer la versión y el mapa en la siguiente pregunta. La llama la Action que cambia concesiones.
     */
    public function refresh(): void
    {
        $this->stamp = null;
        $this->map = null;
    }

    /**
     * @return array{version: int, token: string}
     */
    private function stamp(): array
    {
        if ($this->stamp === null) {
            $row = DB::table('authz_grants_version')->first(['version', 'token']);
            $this->stamp = ['version' => (int) ($row->version ?? 0), 'token' => (string) ($row->token ?? '')];
        }

        return $this->stamp;
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

        [$this->stamp, $map] = $this->load();
        $key = $this->cacheKey();
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
     * Arma el mapa desde la base en una sola consulta, que es un solo estado de la base: la versión y el sello, los
     * roles activos y no borrados que siguen al código (por su puesto de fábrica), las filas de los que no lo siguen y
     * los permisos sueltos. Da también la versión y el sello de ese estado.
     *
     * @return array{0: array{version: int, token: string}, 1: array<string, array<string, true>>}
     */
    private function load(): array
    {
        $rows = DB::select(<<<'SQL'
            select 'stamp' as kind, null::text as role, null::text as factory_role, version, token::text as permission
                from authz_grants_version
            union all
            select 'factory', pps.role, ps.factory_role, null, null
                from position_permission_sets pps
                join permission_sets ps on ps.id = pps.permission_set_id
                where ps.is_active and ps.deleted_at is null and ps.follows_factory
            union all
            select 'own', pps.role, null, null, psp.permission
                from position_permission_sets pps
                join permission_sets ps on ps.id = pps.permission_set_id
                join permission_set_permissions psp on psp.permission_set_id = ps.id
                where ps.is_active and ps.deleted_at is null and not ps.follows_factory
            union all
            select 'loose', role, null, null, permission from position_permissions
            SQL);

        $factory = $this->registry->matrix();
        $stamp = ['version' => 0, 'token' => ''];
        $granted = [];

        foreach ($rows as $row) {
            if ($row->kind === 'stamp') {
                $stamp = ['version' => (int) $row->version, 'token' => (string) $row->permission];

                continue;
            }

            $keys = $row->kind === 'factory' ? ($factory[$row->factory_role] ?? []) : [$row->permission];

            foreach ($keys as $key) {
                $granted[$row->role][$key] = true;
            }
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

        return [$stamp, $map];
    }
}
