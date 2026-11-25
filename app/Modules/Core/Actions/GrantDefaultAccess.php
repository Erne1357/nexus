<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Activa la cuenta con la contraseña por defecto y cambio obligatorio (`password_origin = default`, CO-11). Solo la usa
 * el comando `core:provision-staff` (C8): es la excepción a AC-03, porque el panel ya no da esta contraseña. No toca a
 * quien eligió la suya (`password_origin = user`) ni a quien ya la tiene por defecto, y devuelve si escribió. La
 * bitácora nunca lleva la contraseña.
 */
final class GrantDefaultAccess
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    public function handle(User $user, ?User $by = null): bool
    {
        return DB::transaction(function () use ($user, $by): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($locked->password_origin === PasswordOrigin::User && $locked->password !== null) {
                return false;
            }

            if ($locked->password_origin === PasswordOrigin::Default && $locked->password !== null
                && Hash::check($this->defaultPassword->value(), $locked->password)) {
                return false;
            }

            $locked->forceFill([
                'password' => $this->defaultPassword->hash(),
                'password_origin' => PasswordOrigin::Default,
                'must_change_password' => true,
            ])->save();

            activity()->performedOn($locked)->causedBy($by)->event(ActivityEvent::Updated)
                ->log('Activó la cuenta con la contraseña por defecto (comando)');

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
