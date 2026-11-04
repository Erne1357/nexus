<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * El snapshot de nombramientos de cada usuario (spec 9), en caché con la llave
 * `authz:user:v{FORMAT}:{id}:{authz_version}`.
 *
 * - No hay invalidación manual: el trigger de `role_assignments` sube `users.authz_version` en cada alta, cambio o
 *   baja, y la llave cambia con ella.
 * - Vence a la siguiente medianoche en la zona de la app (America/Ciudad_Juarez): la vigencia es por día, así que un
 *   nombramiento que empieza o termina mañana cuenta o deja de contar sin que nada lo toque.
 * - Se escribe en la caché (y en el memo) solo cuando la transacción en curso se confirma (DB::afterCommit; sin
 *   transacción, de inmediato). Una transacción revertida deshace también la subida de `authz_version`, y la
 *   siguiente alta vuelve a usar ese número: un snapshot leído dentro de ella no debe quedar bajo esa llave.
 * - Lo cacheado se lee estricto: si le falta un campo o uno es de otro tipo, se descarta y se vuelve a leer de la base
 *   (un alcance nulo es «sin límite», así que completarlo ampliaría el acceso). FORMAT va en la llave: lo que guardó
 *   un formato anterior no se lee, aunque el nuevo pudiera leerlo; se recarga de la base y se guarda con la llave nueva.
 * - La versión sale del modelo: el guard de sesión lee la fila completa en cada petición, así que no cuesta una
 *   consulta. Un modelo sin `authz_version` cargada (uno recién creado, como los de las factories, o uno leído con
 *   columnas sueltas) no da una llave confiable: sus nombramientos se leen de la base, sin caché. Las pruebas de la
 *   caché leen al usuario de la base (User::find) para pasar por ella.
 *
 * Se registra como `scoped`: memoriza en la instancia los snapshots de la petición en curso, y Octane no los comparte
 * con la siguiente.
 */
final class AuthorizationSnapshots
{
    /**
     * Versión del formato de AuthorizationSnapshot::toArray() en la llave. Súbela cada vez que cambie la forma del
     * snapshot o el significado de un campo, para que nadie lea lo que guardó una versión anterior.
     *
     * - 1: el primero, con `unit_is_department`.
     * - 2: sin `unit_is_department` (3.P9).
     */
    public const int FORMAT = 2;

    /** @var array<string, AuthorizationSnapshot> */
    private array $memo = [];

    public function __construct(private readonly Repository $cache) {}

    public function for(User $user): AuthorizationSnapshot
    {
        $version = $user->getAttributes()['authz_version'] ?? null;

        if ($version === null) {
            return $this->load($user);
        }

        $key = 'authz:user:v'.self::FORMAT.":{$user->getKey()}:{$version}";

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cached = $this->cache->get($key);
        $snapshot = is_array($cached) ? AuthorizationSnapshot::tryFromArray($cached) : null;

        if ($snapshot !== null) {
            return $this->memo[$key] = $snapshot;
        }

        $expiresAt = today()->addDay()->startOfDay();
        $snapshot = $this->load($user);
        $data = $snapshot->toArray();

        DB::afterCommit(function () use ($key, $data, $expiresAt, $snapshot): void {
            $this->cache->put($key, $data, $expiresAt);
            $this->memo[$key] = $snapshot;
        });

        return $snapshot;
    }

    /**
     * Los nombramientos vigentes hoy que llenan Role::scopeColumns(), en una consulta. La base ya lo exige (CHECK
     * `role_assignments_scope_complete`); el filtro queda como defensa.
     */
    private function load(User $user): AuthorizationSnapshot
    {
        $assignments = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->current()
            ->orderBy('id')
            ->get()
            ->filter(fn (RoleAssignment $assignment): bool => collect($assignment->role->scopeColumns())
                ->every(fn (string $column): bool => $assignment->{$column} !== null));

        return new AuthorizationSnapshot($assignments
            ->map(fn (RoleAssignment $assignment): AssignmentScope => new AssignmentScope(
                $assignment->role,
                $assignment->campus_id,
                $assignment->org_unit_id,
                $assignment->career_id,
            ))
            ->values()
            ->all());
    }
}
