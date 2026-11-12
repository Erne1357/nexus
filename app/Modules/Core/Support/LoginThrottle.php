<?php

namespace App\Modules\Core\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Frena a quien adivina contraseñas en el login (AC-14). **Solo frena, nunca bloquea la cuenta** (confirmado el 11 de
 * noviembre de 2026); los números son supuestos por confirmar:
 *
 * - **Por usuario, sin importar la IP.** Cuenta los intentos fallidos de cada username, exista o no (así no delata
 *   cuáles existen), y desde el quinto impone una espera que crece: 1 minuto desde el 5.º, 15 desde el 10.º, 1 hora
 *   desde el 20.º y 6 horas desde el 30.º. Mientras espera, el login responde «Demasiados intentos» sin comparar la
 *   contraseña. La cuenta se olvida al entrar bien o 24 horas después del último fallo.
 * - **Global, para las cuentas con el NIP del SII.** Más de `NIP_FAILURES` fallos contra cuentas con NIP en una ventana
 *   de 10 minutos pausan 10 minutos los inicios de sesión con NIP y se reporta (report()); durante la pausa, esas
 *   cuentas fallan con el mensaje genérico, como una contraseña incorrecta.
 *
 * Todo vive en la caché (Valkey, compartida entre los workers de Octane); la clase no guarda estado. Las llaves usan una
 * huella del username en minúsculas, nunca el username.
 */
final class LoginThrottle
{
    /** Espera, en segundos, desde el número de fallos que se indica (de mayor a menor). */
    public const array WAITS = [30 => 21_600, 20 => 3_600, 10 => 900, 5 => 60];

    /** Segundos tras el último fallo en que se olvida la cuenta de un usuario. */
    public const int FORGET_AFTER = 86_400;

    public const int NIP_FAILURES = 300;

    public const int NIP_WINDOW = 600;

    public const int NIP_PAUSE = 600;

    private const string PREFIX = 'login-throttle|';

    public function __construct(private readonly Repository $cache) {}

    /**
     * Segundos que le faltan a `$username` para volver a intentar; 0 si puede.
     */
    public function waitFor(string $username): int
    {
        return max(0, (int) $this->cache->get($this->key($username).'|until', 0) - now()->getTimestamp());
    }

    /**
     * Anota un intento fallido de `$username` y, si ya llegó a un umbral, le impone la espera.
     */
    public function failed(string $username): void
    {
        $key = $this->key($username);
        $failures = (int) $this->cache->get($key.'|count', 0) + 1;
        $this->cache->put($key.'|count', $failures, self::FORGET_AFTER);
        $wait = self::waitAfter($failures);

        if ($wait > 0) {
            $this->cache->put($key.'|until', now()->getTimestamp() + $wait, $wait);
        }
    }

    /**
     * Entró bien: se olvidan sus fallos.
     */
    public function succeeded(string $username): void
    {
        $key = $this->key($username);
        $this->cache->forget($key.'|count');
        $this->cache->forget($key.'|until');
    }

    /**
     * La espera tras `$failures` fallos seguidos.
     */
    public static function waitAfter(int $failures): int
    {
        foreach (self::WAITS as $from => $seconds) {
            if ($failures >= $from) {
                return $seconds;
            }
        }

        return 0;
    }

    /**
     * Si los inicios de sesión con el NIP del SII están en pausa.
     */
    public function nipPaused(): bool
    {
        return $this->cache->has(self::PREFIX.'nip-paused');
    }

    /**
     * Anota un fallo contra una cuenta con el NIP del SII; al pasar del tope de la ventana, pausa y lo reporta una vez.
     */
    public function nipFailed(): void
    {
        $window = self::PREFIX.'nip-failures|'.intdiv(now()->getTimestamp(), self::NIP_WINDOW);
        $this->cache->add($window, 0, self::NIP_WINDOW * 2);
        $failures = (int) $this->cache->increment($window);

        if ($failures > self::NIP_FAILURES && $this->cache->add(self::PREFIX.'nip-paused', true, self::NIP_PAUSE)) {
            report(new RuntimeException('Se pausaron '.intdiv(self::NIP_PAUSE, 60).' minutos los inicios de sesión con el NIP del SII: más de '
                .self::NIP_FAILURES.' intentos fallidos contra cuentas con NIP en '.intdiv(self::NIP_WINDOW, 60).' minutos.'));
        }
    }

    private function key(string $username): string
    {
        return self::PREFIX.hash('sha256', Str::lower($username));
    }
}
