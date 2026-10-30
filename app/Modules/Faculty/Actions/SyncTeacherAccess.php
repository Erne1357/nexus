<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Actions\EndDepartmentHead;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Pone el usuario, el rol y los alcances de un docente de acuerdo con su estado (docs/arquitectura/arquitectura.md,
 * "Acceso de docentes"). Es idempotente: correrla otra vez sin cambios en el docente no escribe nada.
 *
 * - Docente activo: su usuario es el ligado o, si no tiene, el de username = número de empleado, que se liga sin
 *   tocar su contraseña; si ese usuario ya es de otro docente, aunque esté borrado, devuelve `conflict` y no toca
 *   nada (S15, supuesto por confirmar). Si no existe, lo crea CreateStaffUser con la contraseña por defecto (o
 *   `$passwordHash`, para reutilizar el hash de una corrida) y cambio obligatorio, con el correo del docente si
 *   ningún otro usuario lo usa. Luego activa el usuario, le da el rol `docente` y un alcance de `docente` por
 *   cada campus de sus adscripciones sin borrar; cierra el de un campus donde ya no tiene ninguna (S14, supuesto
 *   por confirmar), con la regla de CloseRoleScopes: ayer si empezó antes de hoy; si no, se borra.
 * - Docente inactivo o borrado (Baja, confirmado el 30 de octubre de 2026): desactiva su usuario, salvo que sea
 *   admin, y termina ayer cada jefatura suya vigente hoy (EndDepartmentHead). Sin usuario no hace nada: un
 *   docente inactivo no recibe usuario. Sus alcances de `docente` se quedan, para cuando se reactive.
 *
 * Cada cambio deja su bitácora: la liga sobre el docente, el alta (CreateStaffUser), el acceso (activo, roles y
 * campus de `docente`, valor anterior y nuevo) sobre el usuario y cada jefatura terminada (EndDepartmentHead).
 * Nunca registra contraseñas. Quién puede lo deciden las Actions y pantallas que la llaman.
 */
final class SyncTeacherAccess
{
    public function __construct(
        private readonly CreateStaffUser $createStaffUser,
        private readonly EndDepartmentHead $endDepartmentHead,
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

            return $result;
        });
    }

    private function grant(Teacher $teacher, ?string $passwordHash): AccessSync
    {
        $result = AccessSync::Unchanged;
        $user = $teacher->user_id === null ? null : User::query()->lockForUpdate()->findOrFail($teacher->user_id);

        if ($user === null) {
            $user = User::query()->where('username', $teacher->employee_number)->lockForUpdate()->first();

            if ($user !== null && Teacher::withTrashed()->where('user_id', $user->getKey())->exists()) {
                return AccessSync::Conflict;
            }

            $result = $user === null ? AccessSync::Created : AccessSync::Linked;
            $user ??= $this->createStaffUser->handle($teacher->employee_number, $teacher->name, $this->freeEmail($teacher->email), $passwordHash);

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

        $user = User::query()->lockForUpdate()->findOrFail($teacher->user_id);
        $changed = false;

        if ($user->is_active && ! $user->hasRole(Role::Admin->value)) {
            $user->forceFill(['is_active' => false])->save();

            $this->logAccess($teacher, $user, ['is_active' => true], ['is_active' => false], ['desactivó su usuario']);
            $changed = true;
        }

        $heads = DepartmentHead::query()->where('user_id', $user->getKey())->currentOn(today())->get();
        $yesterday = today()->subDay()->toImmutable();

        foreach ($heads as $head) {
            $this->endDepartmentHead->handle($head, $yesterday);
            $changed = true;
        }

        return $changed ? AccessSync::Updated : AccessSync::Unchanged;
    }

    private function link(Teacher $teacher, User $user): void
    {
        $teacher->forceFill(['user_id' => $user->getKey()])->save();

        activity()
            ->performedOn($teacher)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['user_id' => $user->getKey()], 'old' => ['user_id' => null]])
            ->log("Ligó al docente {$teacher->employee_number} con el usuario {$user->username}");
    }

    /**
     * Activa el usuario, le da el rol `docente` y deja un alcance de `docente` por campus de sus adscripciones.
     * Devuelve si cambió algo.
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

        $user->unsetRelation('roles');
        $roles = $user->getRoleNames()->sort()->values()->all();

        if (! in_array(Role::Docente->value, $roles, true)) {
            $user->assignRole(SpatieRole::findOrCreate(Role::Docente->value));
            [$old['roles'], $new['roles']] = [$roles, collect([...$roles, Role::Docente->value])->sort()->values()->all()];
            $done[] = 'le dio el rol '.Role::Docente->label();
        }

        $campuses = TeacherDepartment::query()->where('teacher_id', $teacher->getKey())->distinct()->pluck('campus_id')->sort()->values()->all();
        $scopes = $user->roleScopes()
            ->where('role', Role::Docente)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()))
            ->lockForUpdate()
            ->get();
        $had = $scopes->pluck('campus_id')->filter()->unique()->sort()->values()->all();
        $opened = array_values(array_diff($campuses, $had));
        $closing = $scopes->reject(fn (RoleScope $scope): bool => in_array($scope->campus_id, $campuses, true));

        foreach ($opened as $campusId) {
            $user->roleScopes()->create(['role' => Role::Docente, 'campus_id' => $campusId, 'valid_from' => today()->toDateString()]);
        }

        foreach ($closing as $scope) {
            if ($scope->valid_from->lt(today())) {
                $scope->forceFill(['valid_to' => today()->subDay()->toDateString()])->save();
            } else {
                $scope->delete();
            }
        }

        if ($opened !== [] || $closing->isNotEmpty()) {
            $codes = Campus::withTrashed()->whereKey([...$opened, ...$closing->pluck('campus_id')->filter()])->pluck('code', 'id');
            [$old['docente_campus_ids'], $new['docente_campus_ids']] = [$had, $campuses];

            if ($opened !== []) {
                $done[] = 'abrió su alcance de '.Role::Docente->label().' en '.$this->codes($codes, $opened);
            }

            if ($closing->isNotEmpty()) {
                $done[] = 'cerró su alcance de '.Role::Docente->label().' en '.$this->codes($codes, $closing->pluck('campus_id')->filter()->unique()->all());
            }
        }

        if ($done === []) {
            return false;
        }

        $this->logAccess($teacher, $user, $old, $new, $done);

        return true;
    }

    /**
     * Una entrada sobre el usuario con el valor anterior y el nuevo de lo que cambió, y recarga ScopedContext.
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
            ->log("Sincronizó el acceso del docente {$teacher->employee_number}: ".Arr::join($done, ', ', ' y '));

        app(ScopedContext::class)->refresh();
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
