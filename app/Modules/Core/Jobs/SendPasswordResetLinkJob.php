<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Actions\SendPasswordResetLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda en la cola el enlace para restablecer la contraseña de `$username` (SendPasswordResetLink, AC-16). La pantalla
 * de «¿Olvidaste tu contraseña?» lo encola para todo usuario escrito, exista o no, así que su respuesta no busca al
 * usuario ni tarda distinto. Solo lleva el usuario escrito: el token se crea y se manda aquí dentro. Si el correo
 * falla se reintenta un minuto después, con un token nuevo.
 */
final class SendPasswordResetLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $username) {}

    public function handle(SendPasswordResetLink $send): void
    {
        $send->handle($this->username);
    }
}
