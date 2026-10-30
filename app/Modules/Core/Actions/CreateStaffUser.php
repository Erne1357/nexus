<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\StaffUserCreated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un usuario de personal: activo, con la contraseña por defecto (o el hash que se le
 * dé, para reutilizar uno calculado en una corrida masiva) y obligado a cambiarla en su primer
 * acceso. Un username repetido es una ValidationException sobre `username`, también si otra sesión
 * lo toma entre la revisión y el insert. Sin NEXUS_DEFAULT_PASSWORD falla antes de escribir. La
 * bitácora nunca lleva la contraseña. Después del commit avisa con StaffUserCreated.
 */
final class CreateStaffUser
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $username, string $name, ?string $email = null, ?string $passwordHash = null): User
    {
        $passwordHash ??= $this->defaultPassword->hash();

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
                ->log("Dio de alta al usuario {$user->username}");

            StaffUserCreated::dispatch($user);

            return $user;
        });
    }

    private function usernameTaken(): ValidationException
    {
        return ValidationException::withMessages(['username' => 'Ya existe un usuario con ese nombre de usuario.']);
    }
}
