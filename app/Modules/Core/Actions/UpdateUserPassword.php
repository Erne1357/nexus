<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Cambio de contraseña del propio usuario, el que Fortify llama desde `PUT /user/password`
 * (contrato UpdatesUserPasswords). Es también el cambio obligatorio del primer acceso: al
 * guardar, `must_change_password` pasa a false.
 *
 * A diferencia de las demás Actions, valida su entrada: el controlador de Fortify le pasa la
 * petición sin validar. Exige la contraseña actual y una nueva confirmada, distinta de la actual
 * y conforme a Password::defaults() (FortifyServiceProvider de Core). Rechaza también la contraseña
 * por defecto (DefaultPassword) como contraseña nueva. Rota el remember token, así que ninguna cookie de "recordarme" anterior vuelve a servir; las demás sesiones abiertas
 * las cierra AuthenticateSession en su siguiente petición. La bitácora registra el cambio, nunca
 * la contraseña.
 */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(private readonly DefaultPassword $defaultPassword) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:'.config('fortify.guard')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password', $this->notTheDefaultPassword()],
        ])->validate();

        DB::transaction(function () use ($user, $validated): void {
            $user->forceFill([
                'password' => $validated['password'],
                'must_change_password' => false,
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('password-changed')
                ->log('Cambió su contraseña');
        });
    }

    private function notTheDefaultPassword(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $this->defaultPassword->matches($value)) {
                $fail('No puedes usar la contraseña por defecto.');
            }
        };
    }
}
