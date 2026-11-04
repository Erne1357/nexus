<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\RoleAssignmentEnded;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Termina un nombramiento con `$lastDay` como último día, con la regla de CloseRoleScopes (confirmado el 29 de
 * octubre de 2026): si ya empezó, `valid_to` pasa a ser `$lastDay`; si empieza después de `$lastDay`, se quita,
 * porque `valid_to` no puede ser anterior a `valid_from`; si ya había empezado y `$lastDay` cae antes de su inicio,
 * es un error. Nunca alarga: uno que ya termina ese día o antes se queda como está, sin bitácora ni aviso.
 *
 * Relee el nombramiento con `lockForUpdate`; si otra sesión lo borró, es un error de validación. Antes de escribir
 * pide a RoleAssignmentRules::adminGap() que ningún día se quede sin admin vigente (AZ-S12): el admin puede terminar
 * su propio puesto si otro cubre los días que siguen, y el último nunca se termina. En un puesto de Administración toma
 * primero el candado consultivo RoleAssignmentRules::ADMIN_SEATS_LOCK, antes que el del renglón: dos terminaciones
 * simultáneas se esperan en lugar de trabarse, y la segunda revisa la cobertura con lo que la primera confirmó. Deja la
 * bitácora sobre el usuario con `role_assignment_id`, recarga ScopedContext y avisa con RoleAssignmentEnded después
 * del commit.
 */
final class EndRoleAssignment
{
    public function __construct(
        private readonly RoleAssignmentRules $rules,
        private readonly AssignmentLabels $labels,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(RoleAssignment $assignment, CarbonImmutable $lastDay): AssignmentEnding
    {
        $last = $lastDay->toDateString();

        return DB::transaction(function () use ($assignment, $lastDay, $last): AssignmentEnding {
            if ($assignment->role === Role::Admin) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', [RoleAssignmentRules::ADMIN_SEATS_LOCK]);
            }

            $locked = RoleAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['assignment' => 'Ese nombramiento ya no existe.']);
            }

            if ($locked->valid_to !== null && $locked->valid_to->toDateString() <= $last) {
                $assignment->setRawAttributes($locked->getAttributes(), sync: true);

                return AssignmentEnding::Unchanged;
            }

            $from = $locked->valid_from->toDateString();
            $removing = false;

            if ($from > $last) {
                if ($from <= today()->toDateString()) {
                    throw ValidationException::withMessages([
                        'lastDay' => "La fecha de fin no puede ser anterior al inicio ({$locked->valid_from->format('d/m/Y')}).",
                    ]);
                }

                $removing = true;
            }

            if (($gap = $this->rules->adminGap($locked, $lastDay)) !== null) {
                throw ValidationException::withMessages(['lastDay' => $gap]);
            }

            $locked->load('user');
            $user = $locked->user;
            $summary = $this->labels->one($locked)->summary;
            $old = [
                'role' => $locked->role->value,
                'campus_id' => $locked->campus_id,
                'org_unit_id' => $locked->org_unit_id,
                'career_id' => $locked->career_id,
                'valid_from' => $from,
                'valid_to' => $locked->valid_to?->toDateString(),
            ];
            $properties = ['role_assignment_id' => $locked->getKey()];

            if ($removing) {
                $locked->delete();

                activity()
                    ->performedOn($user)
                    ->event(ActivityEvent::Deleted)
                    ->withChanges(['old' => $old])
                    ->withProperties($properties)
                    ->log("Quitó el nombramiento de {$user->name} como {$summary}, que todavía no empezaba");

                $ending = AssignmentEnding::Removed;
            } else {
                $locked->forceFill(['valid_to' => $last])->save();

                activity()
                    ->performedOn($user)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['valid_to' => $last], 'old' => ['valid_to' => $old['valid_to']]])
                    ->withProperties($properties)
                    ->log("Terminó el nombramiento de {$user->name} como {$summary}");

                $ending = AssignmentEnding::Ended;
            }

            $assignment->setRawAttributes($locked->getAttributes(), sync: true);

            app(ScopedContext::class)->refresh();

            RoleAssignmentEnded::dispatch($locked, $ending);

            return $ending;
        });
    }
}
