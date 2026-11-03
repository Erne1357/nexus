<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;

/**
 * El snapshot de nombramientos de cada usuario (spec 9), en caché con la llave `authz:user:{id}:{authz_version}`.
 *
 * - No hay invalidación manual: el trigger de `role_assignments` sube `users.authz_version` en cada alta, cambio o
 *   baja, y la llave cambia con ella.
 * - Vence a la siguiente medianoche en la zona de la app (America/Ciudad_Juarez): la vigencia es por día, así que un
 *   nombramiento que empieza o termina mañana cuenta o deja de contar sin que nada lo toque.
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
    /** @var array<string, AuthorizationSnapshot> */
    private array $memo = [];

    public function __construct(private readonly Repository $cache) {}

    public function for(User $user): AuthorizationSnapshot
    {
        $version = $user->getAttributes()['authz_version'] ?? null;

        if ($version === null) {
            return $this->load($user);
        }

        $key = "authz:user:{$user->getKey()}:{$version}";

        return $this->memo[$key] ??= AuthorizationSnapshot::fromArray($this->cache->remember(
            $key,
            today()->addDay()->startOfDay(),
            fn (): array => $this->load($user)->toArray(),
        ));
    }

    /**
     * Los nombramientos vigentes hoy que llenan Role::scopeColumns(), con el tipo de su unidad, en una consulta.
     */
    private function load(User $user): AuthorizationSnapshot
    {
        $assignments = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->current()
            ->withExists([
                'orgUnit as unit_is_department' => fn (Builder $query) => $query->withTrashed()->where('kind', OrgUnitKind::DepartamentoAcademico),
            ])
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
                (bool) $assignment->unit_is_department,
            ))
            ->values()
            ->all());
    }
}
