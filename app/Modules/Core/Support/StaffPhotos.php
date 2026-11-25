<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las fotos del personal en el disco privado `staff_photos` (CO-03, CO-04): `<id del usuario>/<AAAAMMDDHHMMSS>.jpg` (la
 * normal de 600×800), `…-mini.jpg` (150×200) y `…-original.<ext>`. Una foto nueva no borra las anteriores: quitarla o
 * reemplazarla solo cambia `users.photo_path`. Nunca se sirve por una URL pública: la sirve el controlador StaffPhoto
 * tras una URL firmada (StaffPhotoUrl).
 */
final class StaffPhotos
{
    /** @var list<string> */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly PhotoNormalizer $normalizer) {}

    /**
     * Normaliza `$sourcePath` y guarda la normal, la miniatura y el original; devuelve la ruta de la normal.
     *
     * @throws InvalidPhoto
     */
    public function store(User $user, string $sourcePath, string $extension): string
    {
        $photos = $this->normalizer->normalize($sourcePath);
        $extension = in_array($extension = strtolower($extension), self::EXTENSIONS, true) ? $extension : 'jpg';

        $stamp = now();
        while ($this->disk()->exists($path = "{$user->getKey()}/{$stamp->format('YmdHis')}.jpg")) {
            $stamp = $stamp->addSecond();
        }

        $base = substr($path, 0, -4);
        $this->disk()->put($path, $photos['normal']);
        $this->disk()->put("{$base}-mini.jpg", $photos['thumb']);
        $this->disk()->put("{$base}-original.{$extension}", (string) file_get_contents($sourcePath));

        return $path;
    }

    /**
     * La miniatura vigente como `data:` URL, o null sin foto.
     */
    public function thumbDataUrl(User $user): ?string
    {
        $path = $this->thumbPath($user);

        if ($path === null || ! $this->disk()->exists($path)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) $this->disk()->get($path));
    }

    /**
     * Qué foto es la vigente (para la URL): cambia con cada foto nueva; null sin foto.
     */
    public function version(User $user): ?string
    {
        if ($user->photo_path === null || $user->photo_updated_at === null) {
            return null;
        }

        return (string) $user->photo_updated_at->getTimestamp();
    }

    /**
     * La foto normal vigente en flujo, con su caché privada de 2 horas; null si no hay foto o falta el archivo.
     */
    public function stream(User $user): ?StreamedResponse
    {
        if ($user->photo_path === null || ! $this->disk()->exists($user->photo_path)) {
            return null;
        }

        return $this->disk()->response($user->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age='.StaffPhotoUrl::TTL_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function thumbPath(User $user): ?string
    {
        return $user->photo_path === null ? null : substr($user->photo_path, 0, -4).'-mini.jpg';
    }

    private function disk(): Filesystem
    {
        return Storage::disk('staff_photos');
    }
}
