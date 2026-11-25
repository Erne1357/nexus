<?php

use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Support\PhotoNormalizer;

/*
| Normalización de las fotos del personal con GD (CO-03, CO-S6, ADR-0034). Imágenes ficticias generadas al vuelo.
*/

/** Una imagen ficticia en disco con el color de arriba distinto del de abajo, para verificar el recorte pegado arriba. */
function photoFixture(int $width, int $height, string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, intdiv($height, 3), imagecolorallocate($image, 250, 0, 0));
    imagefilledrectangle($image, 0, intdiv($height, 3) + 1, $width, $height, imagecolorallocate($image, 0, 0, 250));

    $path = tempnam(sys_get_temp_dir(), 'ph');
    match ($format) {
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path),
        default => imagejpeg($image, $path),
    };

    return $path;
}

it('recorta 3:4 pegado arriba: una imagen de 853×1280 da 600×800 y miniatura 150×200', function () {
    $result = (new PhotoNormalizer)->normalize(photoFixture(853, 1280));

    [$width, $height] = getimagesizefromstring($result['normal']);
    expect([$width, $height])->toBe([600, 800]);
    expect(array_slice(getimagesizefromstring($result['thumb']), 0, 2))->toBe([150, 200]);

    // El tercio de arriba (rojo) se conserva: el recorte no es centrado a lo alto.
    $top = imagecreatefromstring($result['normal']);
    $rgb = imagecolorat($top, 300, 20);
    expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(200);
});

it('recorta una imagen horizontal centrada a lo ancho', function () {
    $result = (new PhotoNormalizer)->normalize(photoFixture(1600, 900, 'png'));

    expect(array_slice(getimagesizefromstring($result['normal']), 0, 2))->toBe([600, 800]);
});

it('rechaza un pdf renombrado .jpg', function () {
    $path = tempnam(sys_get_temp_dir(), 'ph').'.jpg';
    file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");

    expect(fn () => (new PhotoNormalizer)->normalize($path))->toThrow(InvalidPhoto::class, 'Solo jpg, png o webp');
});

it('rechaza más de 10 MB', function () {
    $path = tempnam(sys_get_temp_dir(), 'ph');
    file_put_contents($path, str_repeat('a', PhotoNormalizer::MAX_BYTES + 1));

    expect(fn () => (new PhotoNormalizer)->normalize($path))->toThrow(InvalidPhoto::class, '10 MB');
});

it('rechaza un archivo que no existe', function () {
    expect(fn () => (new PhotoNormalizer)->normalize('/no/existe.jpg'))->toThrow(InvalidPhoto::class);
});
