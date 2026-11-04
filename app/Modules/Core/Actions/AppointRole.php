<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\RoleAppointed;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Nombra a un usuario en un puesto sobre el campus, la unidad y la carrera que `Role::scopeColumns()` pide, del día
 * `$from` al `$to` (sin fin si es nulo). Sirve para todos los puestos (3.P8 la reutiliza para las jefaturas); qué
 * puestos se ofrecen en «Personas y puestos» lo decide la pantalla (AZ-S11). Quién puede nombrar lo decide
 * RoleAssignmentPolicy antes de llamarla.
 *
 * Las reglas de alcance son de RoleAssignmentRules, y el puesto Subdirección Académica solo va sobre la unidad
 * Subdirección Académica (AZ-S10). Fuera de la transacción revisa las columnas y las fechas. Dentro bloquea al usuario
 * (inactivo no se nombra) y a la unidad, que relee activa para que una desactivación concurrente espere, y revisa a la
 * misma persona dos veces y el puesto exclusivo ocupado (AZ-S7), cuyo error dice quién lo ocupa. Si otra sesión gana
 * el puesto entre la revisión y la escritura, la exclusión de la base (SQLSTATE 23P01) responde con el mismo error sin
 * nombre; cualquier otro error de la base sigue igual. Todo error de regla es una ValidationException y no se escribe
 * nada.
 *
 * Deja la bitácora sobre el usuario, recarga ScopedContext (quien se nombra a sí mismo ve su puesto en la misma
 * petición) y avisa con RoleAppointed después del commit.
 */
final class AppointRole
{
    public function __construct(
        private readonly RoleAssignmentRules $rules,
        private readonly AssignmentLabels $labels,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, CarbonImmutable $from, ?CarbonImmutable $to = null): RoleAssignment
    {
        $validFrom = $from->toDateString();
        $validTo = $to?->toDateString();

        $this->ensureFits($role, $campus, $unit, $careerId, $validFrom, $validTo);

        return DB::transaction(function () use ($user, $role, $campus, $unit, $careerId, $validFrom, $validTo): RoleAssignment {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['user' => 'El usuario está inactivo.']);
            }

            if ($unit !== null) {
                $unit = OrgUnit::withTrashed()->whereKey($unit->getKey())->lockForUpdate()->first() ?? $unit;
                $this->rules->ensureScopeFits($role, $campus, $unit, $careerId);
            }

            $this->rules->ensureNotDuplicated($locked, $role, $campus, $unit, $careerId, $validFrom, $validTo);
            $this->rules->ensureSeatFree($role, $unit, $validFrom, $validTo);

            try {
                $assignment = $locked->roleAssignments()->create([
                    'role' => $role,
                    'campus_id' => $campus?->getKey(),
                    'org_unit_id' => $unit?->getKey(),
                    'career_id' => $careerId,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ]);
            } catch (QueryException $exception) {
                throw $this->seatLost($exception, $role, $unit) ?? $exception;
            }

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Created)
                ->withProperties(['role_assignment_id' => $assignment->getKey()])
                ->withChanges(['attributes' => [
                    'role' => $role->value,
                    'campus_id' => $campus?->getKey(),
                    'org_unit_id' => $unit?->getKey(),
                    'career_id' => $careerId,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ]])
                ->log("Nombró a {$locked->name} como ".$this->labels->one($assignment)->summary);

            app(ScopedContext::class)->refresh();

            RoleAppointed::dispatch($assignment);

            return $assignment;
        });
    }

    /**
     * Las columnas del puesto y las fechas, juntas en una sola excepción.
     *
     * @throws ValidationException
     */
    private function ensureFits(Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, string $from, ?string $to): void
    {
        $errors = [];

        try {
            $this->rules->ensureScopeFits($role, $campus, $unit, $careerId);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        if ($to !== null && $to < $from) {
            $errors['to'] = ['La fecha de fin no puede ser anterior a la de inicio.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * El rechazo de `role_assignments_one_jefe` o `role_assignments_one_subdireccion` (SQLSTATE 23P01) como error de
     * validación; cualquier otro, nulo.
     */
    private function seatLost(QueryException $exception, Role $role, ?OrgUnit $unit): ?ValidationException
    {
        if ($unit === null || (string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23P01') {
            return null;
        }

        $message = $exception->getMessage();

        if (str_contains($message, 'role_assignments_one_jefe') || str_contains($message, 'role_assignments_one_subdireccion')) {
            return $this->rules->seatTaken($role, $unit);
        }

        return null;
    }
}
