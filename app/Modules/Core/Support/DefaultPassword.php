<?php

namespace App\Modules\Core\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Contraseña por defecto del personal, leída de `auth.default_password` (NEXUS_DEFAULT_PASSWORD).
 * Solo vive en el `.env`: el repositorio es público. Sin valor configurado falla con un mensaje
 * claro, para que nunca se cree un usuario sin contraseña conocida por el administrador.
 *
 * `hash()` calcula el hash una vez por instancia, para reutilizarlo en una corrida masiva.
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
            throw new RuntimeException('Define NEXUS_DEFAULT_PASSWORD en el .env para crear usuarios de personal o reiniciar contraseñas.');
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

    public function hash(): string
    {
        return $this->hash ??= Hash::make($this->value());
    }
}
