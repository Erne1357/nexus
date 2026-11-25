<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Exceptions\InvalidPhoto;
use GdImage;

/**
 * Normaliza una foto de persona con GD (CO-03, CO-S6, ADR-0034): entra un jpg, png o webp de hasta MAX_BYTES y salen dos
 * JPEG, la normal de 600×800 y la miniatura de 150×200, a calidad 85. El recorte es 3:4 (la proporción del hueco de la
 * foto en la credencial), centrado a lo ancho y pegado arriba, porque las fotos de credencial traen la cara en el tercio
 * superior. Lo transparente se aplana sobre blanco.
 */
final class PhotoNormalizer
{
    public const int WIDTH = 600;

    public const int HEIGHT = 800;

    public const int THUMB_WIDTH = 150;

    public const int THUMB_HEIGHT = 200;

    public const int QUALITY = 85;

    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** Tope de la imagen decodificada: un PNG chico puede declarar miles de millones de píxeles (bomba de descompresión). */
    public const int MAX_SIDE = 8000;

    public const int MAX_PIXELS = 40_000_000;

    /** @var list<string> */
    private const array MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array{normal: string, thumb: string} los bytes de cada JPEG
     *
     * @throws InvalidPhoto si no es una imagen jpg, png o webp legible, o pesa más de 10 MB
     */
    public function normalize(string $sourcePath): array
    {
        $bytes = $this->read($sourcePath);
        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw new InvalidPhoto('No se pudo leer la imagen. Solo jpg, png o webp.');
        }

        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new InvalidPhoto('La imagen tiene dimensiones demasiado grandes (máximo 8000 px por lado y 40 millones de píxeles).');
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new InvalidPhoto('No se pudo leer la imagen. Solo jpg, png o webp.');
        }

        $source = $this->upright($source, $bytes);

        $normal = $this->fit($source, self::WIDTH, self::HEIGHT);
        $thumb = $this->fit($normal, self::THUMB_WIDTH, self::THUMB_HEIGHT);

        return ['normal' => $this->jpeg($normal), 'thumb' => $this->jpeg($thumb)];
    }

    /**
     * Endereza una foto de teléfono según la etiqueta EXIF de orientación de un JPEG (1 a 8); sin etiqueta o sin la
     * extensión `exif`, la deja como viene.
     */
    private function upright(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotate = fn (GdImage $source, int $degrees): GdImage => imagerotate($source, $degrees, (int) imagecolorallocate($source, 255, 255, 255)) ?: $source;
        $flip = function (GdImage $source, int $mode): GdImage {
            imageflip($source, $mode);

            return $source;
        };

        return match ($orientation) {
            2 => $flip($image, IMG_FLIP_HORIZONTAL),
            3 => $rotate($image, 180),
            4 => $flip($image, IMG_FLIP_VERTICAL),
            5 => $flip($rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $rotate($image, -90),
            7 => $flip($rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $rotate($image, 90),
            default => $image,
        };
    }

    private function read(string $path): string
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            throw new InvalidPhoto('No se pudo leer el archivo. Solo jpg, png o webp.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidPhoto('La foto pesa más de 10 MB.');
        }

        $bytes = (string) file_get_contents($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::MIMES, true)) {
            throw new InvalidPhoto('Solo jpg, png o webp.');
        }

        return $bytes;
    }

    /**
     * Recorta `$source` a la proporción del destino (centrado a lo ancho, pegado arriba) y la escala.
     */
    private function fit(GdImage $source, int $width, int $height): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth * $height > $sourceHeight * $width) {
            $cropWidth = intdiv($sourceHeight * $width, $height);
            $cropHeight = $sourceHeight;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = intdiv($sourceWidth * $height, $width);
        }

        $cropX = intdiv($sourceWidth - $cropWidth, 2);

        $target = imagecreatetruecolor($width, $height);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $cropX, 0, $width, $height, max(1, $cropWidth), max(1, $cropHeight));

        return $target;
    }

    private function jpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
