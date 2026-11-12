<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\PasswordResetRules;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Los enlaces para restablecer la contraseña por correo (AC-17): los tokens del broker de contraseñas de Laravel, en
 * `password_reset_tokens` (uno por correo, guardado con hash, que vence a los `auth.passwords.*.expire` minutos). El
 * enlace lleva el token y el usuario en la consulta, no en la ruta, para que no quede en las líneas de petición que
 * escribe Octane. Uno nuevo deja sin efecto al anterior, y cambiar el correo del usuario deja sin efecto al suyo,
 * porque el token se guarda por correo.
 *
 * opens() decide si un enlace sirve sin delatar por el tiempo si el usuario existe o tiene un token pendiente: compara
 * siempre un hash, contra uno señuelo si no hay token. Por eso no usa `tokenExists()` del broker, que no compara nada
 * cuando no hay token.
 */
final class PasswordResetTokens
{
    /**
     * Hash señuelo con el que se compara cuando no hay token. Se calcula una vez por proceso con el hasher
     * configurado (mismo algoritmo y costo que los tokens) y no cambia después; no depende de ninguna petición.
     */
    private static ?string $decoyHash = null;

    public function __construct(private readonly PasswordResetRules $rules) {}

    /**
     * Un token nuevo para `$user`, o null si ya se le creó uno hace menos de `auth.passwords.*.throttle` segundos.
     */
    public function create(User $user): ?string
    {
        $broker = $this->broker();

        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return null;
        }

        return $broker->createToken($user);
    }

    public function delete(User $user): void
    {
        $this->broker()->deleteToken($user);
    }

    public function url(User $user, #[SensitiveParameter] string $token): string
    {
        return route('password.reset', ['token' => $token, 'username' => $user->username]);
    }

    /**
     * Minutos que vale un enlace.
     */
    public function minutes(): int
    {
        return (int) config("auth.passwords.{$this->brokerName()}.expire", 60);
    }

    /**
     * El usuario `$username` si el enlace con `$token` le sirve; si no, null.
     */
    public function userFor(string $username, #[SensitiveParameter] string $token): ?User
    {
        $user = User::query()->where('username', $username)->first();

        return $this->opens($user, $token) ? $user : null;
    }

    /**
     * Si el enlace sirve: `$user` existe, AC-16 lo deja restablecer, tiene un token que no ha vencido y `$token` es
     * ese token.
     */
    public function opens(?User $user, #[SensitiveParameter] string $token): bool
    {
        $record = $user?->email === null ? null : DB::table($this->table())->where('email', $user->getEmailForPasswordReset())->first();
        $matches = Hash::check($token, $record->token ?? $this->decoyHash());

        return $user !== null
            && $record !== null
            && $matches
            && Carbon::parse($record->created_at)->addMinutes($this->minutes())->isFuture()
            && $this->rules->allows($user);
    }

    private function broker(): PasswordBroker
    {
        return Password::broker($this->brokerName());
    }

    private function brokerName(): string
    {
        return (string) config('auth.defaults.passwords');
    }

    private function table(): string
    {
        return (string) config("auth.passwords.{$this->brokerName()}.table");
    }

    private function decoyHash(): string
    {
        return self::$decoyHash ??= Hash::make(Str::random(40));
    }
}
