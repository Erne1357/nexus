<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Events\UserDeactivated;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Dar de baja» la cuenta de un usuario del personal (CF-05, confirmado el 14 de noviembre de 2026). En una transacción:
 *
 * - toma RoleAssignmentRules::ADMIN_SEATS_LOCK y luego bloquea al usuario, en el orden de EndRoleAssignment y de
 *   SyncTeacherAccess, y revisa UserAccountRules::deactivationBlocker(): nadie se da de baja a sí mismo, un alumno no
 *   se da de baja aquí (CF-S4) y no se da de baja a quien dejaría algún día sin admin vigente, hoy incluido (AZ-S12);
 * - termina con último día de hoy cada nombramiento vigente y quita los que aún no empiezan, con EndRoleAssignment
 *   (que deja su bitácora por nombramiento), salvo los de docente y alumno (RoleAssignmentRules::accountAssignments()):
 *   el de docente lo maneja Faculty con el registro del docente (AZ-S11), que sigue en el catálogo (CF-09), y el de
 *   alumno la migración del SII (supuesto por confirmar);
 * - deja la cuenta inactiva y sin acceso: sin contraseña (C6), sin invitación pendiente y sin enlace para restablecer,
 *   con el remember token rotado; la sesión abierta se cierra en su siguiente petición (EnsureUserIsActive).
 *
 * La bitácora guarda el motivo, la nota, quién la dio y los nombramientos terminados y quitados, nunca la contraseña.
 * Avisa con UserDeactivated después del commit. Quién puede lo decide UserPolicy. Devuelve cuántos nombramientos
 * terminó y cuántos quitó.
 */
final class DeactivateUser
{
    public function __construct(
        private readonly UserAccountRules $rules,
        private readonly RoleAssignmentRules $assignmentRules,
        private readonly EndRoleAssignment $endRoleAssignment,
        private readonly PasswordResetTokens $tokens,
    ) {}

    /**
     * @return array{ended: int, removed: int}
     *
     * @throws ValidationException sobre `user`
     */
    public function handle(User $user, DeactivationReason $reason, ?string $note, User $actor): array
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        return DB::transaction(function () use ($user, $reason, $note, $actor): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [RoleAssignmentRules::ADMIN_SEATS_LOCK]);

            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (($blocker = $this->rules->deactivationBlocker($locked, $actor)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            $ended = [];
            $removed = [];
            $today = today()->toImmutable();

            foreach ($this->assignmentRules->accountAssignments($locked)->get() as $assignment) {
                /** @var RoleAssignment $assignment */
                match ($this->endRoleAssignment->handle($assignment, $today)) {
                    AssignmentEnding::Ended => $ended[] = $assignment->getKey(),
                    AssignmentEnding::Removed => $removed[] = $assignment->getKey(),
                    AssignmentEnding::Unchanged => null,
                };
            }

            $access = UserAccess::of($locked);

            $this->tokens->delete($locked);

            $locked->forceFill(['is_active' => false, 'password' => null, 'password_origin' => null, 'access_invited_at' => null]);
            $locked->setRememberToken(Str::random(60));
            $locked->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('deactivated')
                ->withChanges([
                    'attributes' => ['is_active' => false, 'access' => UserAccess::None->value],
                    'old' => ['is_active' => true, 'access' => $access->value],
                ])
                ->withProperties([
                    'reason' => $reason->value,
                    'note' => $note,
                    'ended_assignment_ids' => $ended,
                    'removed_assignment_ids' => $removed,
                ])
                ->log("Dio de baja la cuenta de {$locked->username} ({$reason->label()})".$this->summary(count($ended), count($removed)));

            UserDeactivated::dispatch($locked, $reason);

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return ['ended' => count($ended), 'removed' => count($removed)];
        });
    }

    /**
     * « y terminó 1 nombramiento», « y quitó 2 nombramientos», « y terminó 1 nombramiento y quitó 1»; vacío sin ninguno.
     */
    private function summary(int $ended, int $removed): string
    {
        $parts = [];

        if ($ended > 0) {
            $parts[] = 'terminó '.$ended.($ended === 1 ? ' nombramiento' : ' nombramientos');
        }

        if ($removed > 0) {
            $parts[] = 'quitó '.$removed.($ended > 0 ? '' : ($removed === 1 ? ' nombramiento' : ' nombramientos'));
        }

        return $parts === [] ? '' : ' y '.implode(' y ', $parts);
    }
}
