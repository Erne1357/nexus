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

/** Un PNG que solo trae su cabecera, con las dimensiones que se le pidan (una bomba de descompresión). */
function bombPng(int $width, int $height): string
{
    $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
}

it('rechaza una imagen con dimensiones enormes antes de decodificarla', function (int $width, int $height) {
    $path = tempnam(sys_get_temp_dir(), 'ph');
    file_put_contents($path, bombPng($width, $height));

    expect(fn () => (new PhotoNormalizer)->normalize($path))->toThrow(InvalidPhoto::class, 'dimensiones');
})->with([[20000, 20], [7000, 7000]]);

/** Un JPEG de 400×300 con la mitad izquierda roja y la derecha azul, con la etiqueta EXIF de orientación dada. */
function orientedJpeg(int $orientation): string
{
    $image = imagecreatetruecolor(400, 300);
    imagefilledrectangle($image, 0, 0, 199, 299, imagecolorallocate($image, 250, 0, 0));
    imagefilledrectangle($image, 200, 0, 399, 299, imagecolorallocate($image, 0, 0, 250));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation).pack('v', 0).pack('V', 0);
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;

    $path = tempnam(sys_get_temp_dir(), 'ph');
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return $path;
}

it('endereza una foto de teléfono según su orientación EXIF', function () {
    // Orientación 6: hay que girarla 90° a la derecha; la mitad izquierda (roja) queda arriba.
    $result = (new PhotoNormalizer)->normalize(orientedJpeg(6));
    $image = imagecreatefromstring($result['normal']);

    expect(array_slice(getimagesizefromstring($result['normal']), 0, 2))->toBe([600, 800]);
    expect((imagecolorat($image, 150, 20) >> 16) & 0xFF)->toBeGreaterThan(180)
        ->and(imagecolorat($image, 150, 780) & 0xFF)->toBeGreaterThan(180);
});

it('una foto con orientación normal queda como viene', function () {
    $image = imagecreatefromstring((new PhotoNormalizer)->normalize(orientedJpeg(1))['normal']);

    expect((imagecolorat($image, 20, 400) >> 16) & 0xFF)->toBeGreaterThan(180)
        ->and(imagecolorat($image, 580, 400) & 0xFF)->toBeGreaterThan(180);
});

it('una foto grande de cámara con orientación 6 no agota la memoria y sale 600×800 derecha', function () {
    // 6000×4000 (24 millones de píxeles): la mitad izquierda roja y la derecha azul, con la etiqueta de orientación 6.
    $image = imagecreatetruecolor(6000, 4000);
    imagefilledrectangle($image, 0, 0, 2999, 3999, imagecolorallocate($image, 250, 0, 0));
    imagefilledrectangle($image, 3000, 0, 5999, 3999, imagecolorallocate($image, 0, 0, 250));
    ob_start();
    imagejpeg($image, null, 60);
    $jpeg = (string) ob_get_clean();
    unset($image);

    $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6).pack('v', 0).pack('V', 0);
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;
    $path = tempnam(sys_get_temp_dir(), 'ph');
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    unset($jpeg);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $result = (new PhotoNormalizer)->normalize($path);
    $growth = memory_get_peak_usage() - $before;
    unlink($path);

    $normal = imagecreatefromstring($result['normal']);

    expect($growth)->toBeLessThan(128 * 1024 * 1024)
        ->and(array_slice(getimagesizefromstring($result['normal']), 0, 2))->toBe([600, 800])
        ->and((imagecolorat($normal, 300, 20) >> 16) & 0xFF)->toBeGreaterThan(180)
        ->and(imagecolorat($normal, 300, 780) & 0xFF)->toBeGreaterThan(180);
});
