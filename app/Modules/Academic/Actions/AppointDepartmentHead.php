<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Events\DepartmentHeadAppointed;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Permission\Models\Role as SpatieRole;
use Throwable;

/**
 * Nombra a un usuario jefe de un departamento o jefe de proyecto docente de un departamento en un campus, del día
 * `$from` al `$to` (sin fin si es nulo). Jefaturas (confirmado el 30 de octubre de 2026): al guardar recibe rol y
 * alcance. En una transacción escribe la fila de `department_heads`, le da el rol de spatie
 * (`DepartmentHeadKind::role()`) y un alcance (`role_scopes`) con el mismo campus, departamento y fechas, que es lo
 * que cuenta ScopedContext. Quién puede nombrar lo decide la policy antes de llamarla.
 *
 * El jefe es uno por departamento para todo el Tec (confirmado el 30 de octubre de 2026): no lleva campus, y su
 * alcance tampoco. El jefe de proyecto docente es de un campus (supuesto por confirmar). Un campus para el jefe, o
 * ninguno para el jefe de proyecto docente, es una ValidationException sobre `campus`.
 *
 * Un usuario inactivo no se nombra: es una ValidationException sobre `user`. La base rechaza un segundo jefe vigente
 * del departamento, y a la misma persona dos veces en el mismo cargo con fechas que se traslapan (confirmado el 29
 * de octubre de 2026). Los dos rechazos se vuelven una
 * ValidationException sobre `user` y no se escribe nada. Deja la bitácora sobre el usuario, recarga ScopedContext
 * (también el del usuario autenticado) y avisa con DepartmentHeadAppointed después del commit.
 */
final class AppointDepartmentHead
{
    /**
     * @throws ValidationException
     */
    public function handle(Department $department, ?Campus $campus, User $user, DepartmentHeadKind $kind, CarbonImmutable $from, ?CarbonImmutable $to = null): DepartmentHead
    {
        $validFrom = $from->toDateString();
        $validTo = $to?->toDateString();
        $errors = [];

        if ($kind === DepartmentHeadKind::Jefe && $campus !== null) {
            $errors['campus'] = 'El jefe de departamento es de todo el Tec: no lleva campus.';
        }

        if ($kind === DepartmentHeadKind::JefeProyectoDocente && $campus === null) {
            $errors['campus'] = 'Elige el campus del jefe de proyecto docente.';
        }

        if ($validTo !== null && $validTo < $validFrom) {
            $errors['valid_to'] = 'La fecha de fin no puede ser anterior a la de inicio.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($department, $campus, $user, $kind, $validFrom, $validTo): DepartmentHead {
            // Con el usuario bloqueado, dos nombramientos a la vez no le asignan el mismo rol dos veces.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['user' => 'El usuario está inactivo.']);
            }

            try {
                $head = DepartmentHead::query()->create([
                    'department_id' => $department->getKey(),
                    'campus_id' => $campus?->getKey(),
                    'user_id' => $user->getKey(),
                    'kind' => $kind,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ]);
            } catch (QueryException $exception) {
                throw $this->overlapping($exception, $department, $campus, $user, $kind);
            }

            $role = $kind->role();

            $user->unsetRelation('roles');
            $user->assignRole(SpatieRole::findOrCreate($role->value));
            $user->roleScopes()->create([
                'role' => $role,
                'campus_id' => $campus?->getKey(),
                'department_id' => $department->getKey(),
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ]);

            activity()
                ->performedOn($user)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [
                    'department_head_id' => $head->getKey(),
                    'kind' => $kind->value,
                    'department_id' => $department->getKey(),
                    'campus_id' => $campus?->getKey(),
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ]])
                ->log("Nombró a {$user->name} ".$kind->position($department->name, $campus?->name));

            app(ScopedContext::class)->refresh();

            DepartmentHeadAppointed::dispatch($head);

            return $head;
        });
    }

    /**
     * El rechazo de una de las exclusiones de `department_heads` (SQLSTATE 23P01, `exclusion_violation`) como error
     * de validación; otro error de la base sigue igual, aunque su mensaje nombre la restricción.
     */
    private function overlapping(QueryException $exception, Department $department, ?Campus $campus, User $user, DepartmentHeadKind $kind): Throwable
    {
        if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23P01') {
            return $exception;
        }

        $message = $exception->getMessage();

        if (str_contains($message, 'department_heads_one_jefe')) {
            return ValidationException::withMessages(['user' => "Ya hay un jefe de {$department->name} vigente en esas fechas."]);
        }

        if (str_contains($message, 'department_heads_no_duplicate')) {
            return ValidationException::withMessages(['user' => "{$user->name} ya es ".$kind->position($department->name, $campus?->name).' en esas fechas.']);
        }

        return $exception;
    }

}
