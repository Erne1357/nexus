<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\StaffUserCreated;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un usuario de personal, activo y obligado a cambiar su contraseña en su primer acceso. Siempre nace sin
 * acceso, sin contraseña, hasta que reciba su invitación (AC-01, AC-05 y CF-07): así nacen los de los docentes y los
 * del panel, que después invita. Un username repetido es una ValidationException sobre
 * `username`, también si otra sesión lo toma entre la revisión y el insert; un correo que otro usuario ya tiene (sin
 * importar mayúsculas, lo garantiza la base) es una sobre `email`. El correo se guarda como llega: quien llama lo
 * normaliza (el panel, con UserAccountRules, CF-S3).
 * Después del commit avisa con StaffUserCreated.
 */
final class CreateStaffUser
{
    /**
     * @throws ValidationException
     */
    public function handle(string $username, string $name, ?string $email = null): User
    {
        return DB::transaction(function () use ($username, $name, $email): User {
            if (User::where('username', $username)->exists()) {
                throw $this->usernameTaken();
            }

            try {
                $user = User::create([
                    'username' => $username,
                    'name' => $name,
                    'email' => $email,
                    'password' => null,
                    'password_origin' => null,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw str_contains($exception->getMessage(), 'users_email') ? $this->emailTaken((string) $email) : $this->usernameTaken();
            }

            activity()
                ->performedOn($user)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $user->only(['username', 'name', 'email', 'is_active', 'must_change_password'])])
                ->log("Dio de alta al usuario {$user->username}, sin acceso");

            StaffUserCreated::dispatch($user);

            return $user;
        });
    }

    private function usernameTaken(): ValidationException
    {
        return ValidationException::withMessages(['username' => 'Ya existe un usuario con ese nombre de usuario.']);
    }

    private function emailTaken(string $email): ValidationException
    {
        return ValidationException::withMessages(['email' => 'Otro usuario ya tiene el correo '.mb_strtolower($email).'.']);
    }
}
