<?php

namespace App\Modules\Core\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Contraseña por defecto del personal, leída de `auth.default_password` (NEXUS_DEFAULT_PASSWORD).
 * Solo vive en el `.env`: el repositorio es público. Sin valor configurado falla con un mensaje
 * claro, para que no se dé acceso a nadie con una contraseña que el administrador no conoce. Un
 * usuario sin acceso (AC-01) no la necesita: nace sin contraseña.
 *
 * `hash()` calcula el hash una vez por instancia, para reutilizarlo en una corrida masiva. `rejectionRule()` es la
 * regla de validación que no la deja como contraseña nueva (cambio de contraseña), y `newPasswordRules()`, las reglas
 * completas de la contraseña que alguien elige con un enlace de su correo: el restablecimiento (AC-18) y la activación
 * con la invitación (AC-08).
 */
final class DefaultPassword
{
    private ?string $hash = null;

    public function __construct(private readonly Repository $config) {}

    /**
     * @throws RuntimeException
     */
    public function value(): string
    {
        $value = $this->config->get('auth.default_password');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Define NEXUS_DEFAULT_PASSWORD en el .env para comparar o rechazar la contraseña por defecto. El alta del personal ya no la usa: nace sin acceso y entra por invitación (CF-07).');
        }

        return $value;
    }

    /**
     * Si la contraseña dada es la de defecto. Sin variable configurada nada coincide.
     */
    public function matches(string $plain): bool
    {
        $value = $this->config->get('auth.default_password');

        if (! is_string($value) || $value === '') {
            return false;
        }

        return hash_equals($value, $plain);
    }

    /**
     * Regla de validación que rechaza la contraseña por defecto como contraseña nueva.
     */
    public function rejectionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $this->matches($value)) {
                $fail('No puedes usar la contraseña por defecto.');
            }
        };
    }

    /**
     * Las reglas de `password` cuando alguien elige su contraseña con un enlace de su correo (AC-08 y AC-18): una nueva
     * confirmada (`password_confirmation`), conforme a Password::defaults() y que no sea la por defecto.
     *
     * @return list<mixed>
     */
    public function newPasswordRules(): array
    {
        return ['required', 'string', Password::defaults(), 'confirmed', $this->rejectionRule()];
    }

    public function hash(): string
    {
        return $this->hash ??= Hash::make($this->value());
    }
}
