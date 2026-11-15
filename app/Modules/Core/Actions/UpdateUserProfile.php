<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\UserProfileUpdated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\UserEmailChanged;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * «Editar datos» de una cuenta (CF-S3, supuesto por confirmar): el nombre y el correo institucional; el usuario no se
 * edita (un error se corrige dando de baja y de alta). Solo toma `name` y `email` de `$data`. El correo pasa por
 * UserAccountRules (minúsculas, dominio institucional y único sin importar mayúsculas, también si otra sesión lo toma
 * entre la revisión y la escritura). La cuenta de un alumno no se edita (CF-S4).
 *
 * Cambiar el correo deja sin efecto el enlace para restablecer (AC-17: el token se guarda por correo, y se borra el del
 * correo anterior) y la invitación pendiente de quien no tiene contraseña (`access_invited_at` nulo; su enlace ya no
 * abría, porque su huella lleva el correo), y el correo nuevo queda sin confirmar (`email_verified_at` nulo; supuesto
 * por confirmar): a quien no tiene contraseña lo confirma la invitación que le llegue ahí, y al personal que ya la tiene
 * no se le restablece por enlace (AC-16) hasta que se decida cómo confirmarlo. Relee al usuario con `lockForUpdate`; sin
 * cambios no escribe, no deja bitácora ni avisa. La bitácora lleva lo de antes y lo de después; avisa con
 * UserProfileUpdated después del commit. Si había un correo anterior, le avisa del cambio (`notifyPreviousEmail()`).
 */
final class UpdateUserProfile
{
    public function __construct(
        private readonly UserAccountRules $rules,
        private readonly PasswordResetTokens $tokens,
    ) {}

    /**
     * @param  array{name?: string, email?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data, ?User $actor = null): User
    {
        $data = Arr::only($data, ['name', 'email']);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = $this->rules->institutionalEmail($data['email']);
        }

        return DB::transaction(function () use ($user, $data, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (($blocker = $this->rules->updateBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $blocker]);
            }

            if (array_key_exists('email', $data) && $data['email'] === ($locked->email === null ? null : mb_strtolower($locked->email))) {
                unset($data['email']);
            }

            $locked->fill($data);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $old = Arr::only($locked->getRawOriginal(), $changed);

            if (in_array('email', $changed, true)) {
                if ($locked->email !== null) {
                    $this->rules->ensureEmailIsFree($locked->email, $locked);
                }

                if ($old['email'] !== null) {
                    $this->tokens->delete((clone $locked)->setRawAttributes(['email' => $old['email']]));
                }

                $locked->forceFill(['email_verified_at' => null]);

                if ($locked->password === null && $locked->access_invited_at !== null) {
                    $old['access_invited_at'] = $locked->access_invited_at->toIso8601String();
                    $locked->forceFill(['access_invited_at' => null, 'access_invited_by' => null]);
                }
            }

            $new = Arr::only($locked->getAttributes(), array_keys($old));

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->emailTaken((string) $locked->email);
            }

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->log("Editó los datos de {$locked->username}");

            if (in_array('email', $changed, true) && $old['email'] !== null) {
                $this->notifyPreviousEmail($locked, (string) $old['email'], $actor);
            }

            UserProfileUpdated::dispatch($locked, $changed);

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * CF-S3 (confirmado el 15 de noviembre de 2026): avisa al correo anterior quién cambió el correo y cuándo, con el
     * nuevo parcialmente oculto y sin enlaces (UserEmailChanged, de la cola después del commit), y lo deja en la
     * bitácora.
     */
    private function notifyPreviousEmail(User $user, string $previous, ?User $actor): void
    {
        Notification::route('mail', $previous)->notify(new UserEmailChanged(
            $user->username,
            $user->name,
            $actor?->name ?? 'La administración de Nexus',
            CarbonImmutable::now(),
            $user->email,
        ));

        activity()
            ->performedOn($user)
            ->causedBy($actor)
            ->event('email-change-notified')
            ->withProperties(['email' => $previous])
            ->log("Avisó del cambio de correo de {$user->username} a su correo anterior, {$previous}");
    }
}
