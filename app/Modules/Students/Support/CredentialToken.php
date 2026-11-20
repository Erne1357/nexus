<?php

namespace App\Modules\Students\Support;

use Illuminate\Support\Str;

/**
 * El token del QR de la credencial (AC-20, tarea 1.27): cambia cada 30 s y solo lo puede armar el servidor. Lleva el id
 * del alumno y la ventana de 30 s cifrados y autenticados con AES-256-GCM (llave derivada de APP_KEY), así que la URL no
 * deja ver ni el id ni el número de control, no se puede adivinar ni recorrer, y un token copiado deja de servir. Vale en
 * su ventana y en la siguiente (hasta 60 s), para que alcance a escanearse justo cuando cambia.
 */
final class CredentialToken
{
    public const int WINDOW_SECONDS = 30;

    private const string CIPHER = 'aes-256-gcm';

    /**
     * El token del alumno para el instante `$now` (segundos Unix).
     */
    public function issue(string $studentId, int $now): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $plain = pack('N', self::window($now)).hex2bin(str_replace('-', '', $studentId));
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);

        return self::base64url($nonce.$tag.$cipher);
    }

    /**
     * El id del alumno si el token es auténtico y sigue vigente en `$now`; 'expired' si es auténtico pero venció; null si
     * no es un token de Nexus.
     *
     * @return array{student: string, window: int}|'expired'|null
     */
    public function verify(string $token, int $now): array|string|null
    {
        $raw = self::unbase64url($token);

        if ($raw === null || strlen($raw) !== 12 + 16 + 20) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        if ($plain === false || strlen($plain) !== 20) {
            return null;
        }

        $window = unpack('N', substr($plain, 0, 4))[1];
        $current = self::window($now);

        if ($window > $current || $window < $current - 1) {
            return 'expired';
        }

        $hex = bin2hex(substr($plain, 4));

        return ['student' => substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20), 'window' => $window];
    }

    /**
     * Los segundos que le quedan a la ventana de `$now` (1 a 30): cuando llegan a cero la credencial pide un QR nuevo.
     */
    public static function secondsLeft(int $now): int
    {
        return self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);
    }

    public static function window(int $now): int
    {
        return intdiv($now, self::WINDOW_SECONDS);
    }

    private function key(): string
    {
        $appKey = (string) config('app.key');
        $raw = Str::startsWith($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;

        return hash_hmac('sha256', 'nexus.student-credential.v1', $raw, true);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unbase64url(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,100}$/', $text) !== 1) {
            return null;
        }

        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
