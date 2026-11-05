<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone el usuario y los nombramientos (`role_assignments`) de un docente de acuerdo con su estado
 * (docs/arquitectura/arquitectura.md, "Acceso de docentes"). Es idempotente: correrla otra vez sin cambios en el
 * docente no escribe nada.
 *
 * - Docente activo: su usuario es el ligado o, si no tiene, el de username = RFC, que se liga sin
 *   tocar su contraseña (S15, supuesto por confirmar), solo si está activo, no es de otro docente (aunque esté
 *   borrado) y no tiene más puesto que `docente` (supuesto por confirmar): si no, devuelve `conflict` y no toca
 *   nada, porque quien da de alta un docente no debe poder reactivar ni, con la baja, desactivar una cuenta de
 *   personal; eso lo resuelve el admin. Si no existe, lo crea CreateStaffUser con la contraseña por defecto (o
 *   `$passwordHash`, para reutilizar el hash de una corrida) y cambio obligatorio, con el correo del docente si
 *   ningún otro usuario lo usa (supuesto por confirmar). Luego activa el usuario ya ligado (reactivar al docente
 *   lo devuelve) y le da su nombramiento de `docente`. El docente no es de un campus (confirmado el 30 de octubre de 2026); de
 *   ahí sale que su alcance de `docente` sea uno solo, sin campus, y que no dependa de sus adscripciones: un docente
 *   sin departamento conserva usuario y nombramiento. Uno sin campus que todavía no empieza cuenta como el suyo y no
 *   se abre otro (supuesto por confirmar). Cierra los alcances de `docente` con campus que quedaron de antes, con la
 *   regla de CloseRoleScopes: ayer si empezó antes de hoy; si no, se borra.
 * - Docente inactivo, o borrado (supuesto por confirmar), es la baja (confirmado el 30 de octubre de 2026):
 *   desactiva su usuario, salvo que sea admin vigente o que su Administración programada haga falta para que ningún
 *   día se quede sin admin (AZ-S12 y P3, confirmado el 3 de noviembre de 2026: el docente queda de baja y el usuario
 *   sigue activo; toma ADMIN_SEATS_LOCK antes del candado del usuario), y termina con fecha de ayer cada jefatura suya vigente o por
 *   empezar (supuesto por confirmar; EndRoleAssignment quita las que empiezan hoy o después). Sin usuario no hace
 *   nada: un docente inactivo no recibe usuario. Sus alcances de `docente` se quedan, para cuando se reactive.
 *
 * Cada cambio deja su bitácora: la liga sobre el docente, el alta (CreateStaffUser), el acceso (activo, el
 * alcance de `docente` de todo el Tec y los campus de `docente` cerrados, valor anterior y nuevo) sobre el usuario
 * y cada jefatura terminada (EndRoleAssignment). Nunca registra contraseñas. Si cambió algo, avisa con
 * TeacherAccessChanged después del commit. Quién puede lo deciden las Actions y pantallas que la llaman.
 */
final class SyncTeacherAccess
{
    public function __construct(
        private readonly CreateStaffUser $createStaffUser,
        private readonly EndRoleAssignment $endRoleAssignment,
        private readonly RoleAssignmentRules $rules,
    ) {}

    /**
     * @throws RuntimeException si hay que crear el usuario, no llega `$passwordHash` y falta NEXUS_DEFAULT_PASSWORD
     * @throws ValidationException si otra sesión crea el username entre la revisión y el alta
     */
    public function handle(Teacher $teacher, ?string $passwordHash = null): AccessSync
    {
        return DB::transaction(function () use ($teacher, $passwordHash): AccessSync {
            $locked = Teacher::withTrashed()->lockForUpdate()->findOrFail($teacher->getKey());

            $result = $locked->is_active && ! $locked->trashed()
                ? $this->grant($locked, $passwordHash)
                : $this->revoke($locked);

            $teacher->setRawAttributes($locked->getAttributes(), sync: true);
            $teacher->unsetRelation('user');

            if ($result !== AccessSync::Unchanged && $result !== AccessSync::Conflict) {
                TeacherAccessChanged::dispatch($teacher, $result);
            }

            return $result;
        });
    }

    private function grant(Teacher $teacher, ?string $passwordHash): AccessSync
    {
        $result = AccessSync::Unchanged;
        $user = $teacher->user_id === null ? null : User::query()->lockForUpdate()->findOrFail($teacher->user_id);

        if ($user === null) {
            $user = User::query()->where('username', $teacher->rfc)->lockForUpdate()->first();

            if ($user !== null && ! $this->linkable($user)) {
                return AccessSync::Conflict;
            }

            $result = $user === null ? AccessSync::Created : AccessSync::Linked;
            $user ??= $this->createStaffUser->handle($teacher->rfc, $teacher->name, $this->freeEmail($teacher->email), $passwordHash);

            $this->link($teacher, $user);
        }

        $changed = $this->grantAccess($teacher, $user);

        return $result === AccessSync::Unchanged && $changed ? AccessSync::Updated : $result;
    }

    private function revoke(Teacher $teacher): AccessSync
    {
        if ($teacher->user_id === null) {
            return AccessSync::Unchanged;
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?))', [RoleAssignmentRules::ADMIN_SEATS_LOCK]);

        $user = User::query()->lockForUpdate()->findOrFail($teacher->user_id);
        $changed = false;

        if ($user->is_active
            && ! $user->roleAssignments()->current()->where('role', Role::Admin)->exists()
            && $this->rules->deactivationGap($user) === null) {
            $user->forceFill(['is_active' => false])->save();

            $this->logAccess($teacher, $user, ['is_active' => true], ['is_active' => false], ['desactivó su usuario']);
            $changed = true;
        }

        $heads = $user->roleAssignments()
            ->whereIn('role', Role::HEADSHIPS)
            ->notEnded()
            ->get();
        $yesterday = today()->subDay()->toImmutable();

        foreach ($heads as $head) {
            $this->endRoleAssignment->handle($head, $yesterday);
            $changed = true;
        }

        return $changed ? AccessSync::Updated : AccessSync::Unchanged;
    }

    /**
     * Si un usuario existente con el número del docente se puede ligar: activo, sin otro docente ligado (aunque esté
     * borrado) y sin más puesto que `docente`, ni vigente ni ya terminado ni por empezar: quien fue personal sigue siéndolo para
     * esta regla, y la baja del docente no debe desactivar esa cuenta (S15 y supuesto por confirmar).
     */
    private function linkable(User $user): bool
    {
        return $user->is_active
            && ! $user->roleAssignments()
                ->where('role', '<>', Role::Docente)
                ->exists()
            && ! Teacher::withTrashed()->where('user_id', $user->getKey())->exists();
    }

    private function link(Teacher $teacher, User $user): void
    {
        $teacher->forceFill(['user_id' => $user->getKey()])->save();

        activity()
            ->performedOn($teacher)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['user_id' => $user->getKey()], 'old' => ['user_id' => null]])
            ->log("Ligó al docente {$teacher->rfc} con el usuario {$user->username}");
    }

    /**
     * Activa el usuario, le deja un alcance de `docente` sin campus y cierra los que tienen
     * campus. Devuelve si cambió algo.
     */
    private function grantAccess(Teacher $teacher, User $user): bool
    {
        $old = [];
        $new = [];
        $done = [];

        if (! $user->is_active) {
            $user->forceFill(['is_active' => true])->save();
            [$old['is_active'], $new['is_active']] = [false, true];
            $done[] = 'activó su usuario';
        }

        $scopes = $user->roleAssignments()
            ->where('role', Role::Docente)
            ->notEnded()
            ->lockForUpdate()
            ->get();
        $closing = $scopes->filter(fn (RoleAssignment $scope): bool => $scope->campus_id !== null);

        if ($scopes->every(fn (RoleAssignment $scope): bool => $scope->campus_id !== null)) {
            $user->roleAssignments()->create(['role' => Role::Docente, 'valid_from' => today()->toDateString()]);
            [$old['docente_general'], $new['docente_general']] = [false, true];
            $done[] = 'abrió su alcance de '.Role::Docente->label().' en todo el Tec';
        }

        if ($closing->isNotEmpty()) {
            foreach ($closing as $scope) {
                if ($scope->valid_from->lt(today())) {
                    $scope->forceFill(['valid_to' => today()->subDay()->toDateString()])->save();
                } else {
                    $scope->delete();
                }
            }

            $campusIds = $closing->pluck('campus_id')->unique()->sort()->values()->all();
            $codes = Campus::withTrashed()->whereKey($campusIds)->pluck('code', 'id');
            [$old['docente_campus_ids'], $new['docente_campus_ids']] = [$campusIds, []];
            $done[] = 'cerró su alcance de '.Role::Docente->label().' en '.$this->codes($codes, $campusIds);
        }

        if ($done === []) {
            return false;
        }

        $this->logAccess($teacher, $user, $old, $new, $done);

        return true;
    }

    /**
     * Una entrada sobre el usuario con el valor anterior y el nuevo de lo que cambió, y recarga Actor.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $done
     */
    private function logAccess(Teacher $teacher, User $user, array $old, array $new, array $done): void
    {
        activity()
            ->performedOn($user)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => $new, 'old' => $old])
            ->withProperties(['teacher_id' => $teacher->getKey()])
            ->log("Sincronizó el acceso del docente {$teacher->rfc}: ".Arr::join($done, ', ', ' y '));

        app(Actor::class)->refresh();
    }

    /**
     * @param  Collection<string, string>  $codes  clave de cada campus, por id
     * @param  array<int, string>  $campusIds
     */
    private function codes(Collection $codes, array $campusIds): string
    {
        return Arr::join(collect($campusIds)->map(fn (string $id): string => $codes[$id] ?? $id)->sort()->values()->all(), ', ', ' y ');
    }

    /**
     * El correo del docente para su usuario nuevo, salvo que otro usuario ya lo use (`users.email` es único).
     */
    private function freeEmail(?string $email): ?string
    {
        return $email !== null && ! User::query()->where('email', $email)->exists() ? $email : null;
    }
}
