<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Events\UserReactivated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Reactivar» una cuenta dada de baja en el panel (CF-06, confirmado el 14 de noviembre de 2026): vuelve activa y sin
 * acceso, y sus puestos no regresan solos (se nombran otra vez en la ficha). Solo reactiva una cuenta que dio de baja el
 * panel (`deactivated_at`, UserAccountRules::reactivationBlocker(); supuesto por confirmar): la de un docente inactivo se
 * reactiva en Docentes. Es el único camino que quita esa marca (CF-09). Después del commit, si tiene correo
 * institucional y quien reactiva puede invitar, le manda la invitación del admin (InviteUser, CF-07); si no, o si no se
 * pudo mandar, queda «Sin acceso» hasta que el admin capture su correo y la mande desde la ficha.
 *
 * La baja termina los puestos con último día de hoy, que todavía cuenta como vigente: si se reactiva el mismo día, esos
 * nombramientos terminan ayer, o se quitan si empezaban hoy, con EndRoleAssignment, para que los puestos no regresen
 * hasta la medianoche (menos el de docente y el de alumno, que la baja no toca). Por eso toma primero
 * RoleAssignmentRules::ADMIN_SEATS_LOCK y luego bloquea al usuario, en el orden de DeactivateUser. Deja la cuenta sin
 * contraseña, sin invitación y sin enlace para restablecer pendiente, aunque algo se los hubiera puesto mientras estaba
 * dada de baja.
 *
 * Deja bitácora sobre el usuario (sin contraseña) y avisa con UserReactivated después del commit. Quién puede lo decide
 * UserPolicy. Devuelve el correo al que mandó la invitación, o null si no la mandó.
 */
final class ReactivateUser
{
    public function __construct(
        private readonly UserAccountRules $rules,
        private readonly RoleAssignmentRules $assignmentRules,
        private readonly EndRoleAssignment $endRoleAssignment,
        private readonly PasswordResetTokens $tokens,
        private readonly InviteUser $inviteUser,
    ) {}

    /**
     * @throws ValidationException sobre `user`
     */
    public function handle(User $user, User $actor): ?string
    {
        DB::transaction(function () use ($user, $actor): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [RoleAssignmentRules::ADMIN_SEATS_LOCK]);

            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (($blocker = $this->rules->reactivationBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            if ($locked->deactivated_at->setTimezone(config('app.timezone'))->isToday()) {
                $this->endTodaysAssignments($locked);
            }

            $access = UserAccess::of($locked);
            $old = ['is_active' => false, 'deactivated_at' => $locked->deactivated_at->toIso8601String()];
            $new = ['is_active' => true, 'deactivated_at' => null];

            if ($access !== UserAccess::None) {
                [$old['access'], $new['access']] = [$access->value, UserAccess::None->value];
                $locked->forceFill(['password' => null, 'password_origin' => null, 'access_invited_at' => null, 'access_invited_by' => null]);
                $locked->setRememberToken(Str::random(60));
            }

            $this->tokens->delete($locked);

            $locked->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reactivated')
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->log("Reactivó la cuenta de {$locked->username}, sin acceso");

            UserReactivated::dispatch($locked);

            $user->setRawAttributes($locked->getAttributes(), sync: true);
        });

        return $this->invite($user, $actor);
    }

    /**
     * CF-06 y CF-07: la invitación de la cuenta ya reactiva, si tiene correo institucional y `$actor` puede invitar
     * (UserPolicy, `invite`). Un rechazo (otra sesión le cambió algo en medio) no deshace la reactivación: queda «Sin
     * acceso».
     */
    private function invite(User $user, User $actor): ?string
    {
        if (Gate::forUser($actor)->denies('invite', $user) || $this->rules->invitationBlocker($user) !== null) {
            return null;
        }

        try {
            return $this->inviteUser->handle($user, $actor);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Los nombramientos que la baja de hoy dejó terminando hoy terminan ayer, o se quitan si empezaban hoy. Un rechazo
     * de EndRoleAssignment (como el de AZ-S12) sale sobre `user` y revierte todo.
     */
    private function endTodaysAssignments(User $user): void
    {
        $yesterday = today()->subDay()->toImmutable();

        foreach ($this->assignmentRules->accountAssignmentsEndingToday($user)->get() as $assignment) {
            try {
                $this->endRoleAssignment->handle($assignment, $yesterday);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['user' => (string) collect($exception->errors())->flatten()->first()]);
            }
        }
    }
}
