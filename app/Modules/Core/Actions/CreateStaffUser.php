<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\StaffUserCreated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un usuario de personal, activo y obligado a cambiar su contraseña en su primer acceso. Quien lo da de alta
 * decide siempre si nace con acceso (`$withAccess` no tiene valor por omisión): sin acceso, sin contraseña, hasta que
 * se le dé (AC-01 y AC-02), así nacen los de los docentes; con acceso, con la contraseña por defecto, que es el alta
 * manual del admin (AC-05, supuesto por confirmar), y sin NEXUS_DEFAULT_PASSWORD falla antes de escribir. Un username repetido es una ValidationException sobre
 * `username`, también si otra sesión lo toma entre la revisión y el insert. La bitácora nunca lleva la contraseña.
 * Después del commit avisa con StaffUserCreated.
 */
final class CreateStaffUser
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si `$withAccess` y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(string $username, string $name, bool $withAccess, ?string $email = null): User
    {
        $passwordHash = $withAccess ? $this->defaultPassword->hash() : null;

        return DB::transaction(function () use ($username, $name, $email, $passwordHash): User {
            if (User::where('username', $username)->exists()) {
                throw $this->usernameTaken();
            }

            try {
                $user = User::create([
                    'username' => $username,
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->usernameTaken();
            }

            activity()
                ->performedOn($user)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $user->only(['username', 'name', 'email', 'is_active', 'must_change_password'])])
                ->log("Dio de alta al usuario {$user->username}".($passwordHash === null ? ', sin acceso' : ''));

            StaffUserCreated::dispatch($user);

            return $user;
        });
    }

    private function usernameTaken(): ValidationException
    {
        return ValidationException::withMessages(['username' => 'Ya existe un usuario con ese nombre de usuario.']);
    }
}
