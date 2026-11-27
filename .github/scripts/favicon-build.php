<?php

/*
 * Genera public/favicon.ico (16, 32 y 48 px) y public/apple-touch-icon.png (180 px) desde la silueta oficial de la
 * liebre (resources/images/liebre-512.png): la liebre en blanco sobre el cuadro rojo Tec #c2201e, como en la banda
 * (DESIGN.md, «La liebre»). Se dibuja a 1024 px y se reduce a cada tamaño para que las orillas salgan suaves.
 * Uso, desde la raíz del repo o del worktree y dentro del contenedor: php .github/scripts/favicon-build.php
 */

$root = getcwd();
$source = imagecreatefrompng("{$root}/resources/images/liebre-512.png");
imagesavealpha($source, true);

// La silueta en blanco conservando su alfa.
$white = imagecreatetruecolor(512, 512);
imagealphablending($white, false);
imagesavealpha($white, true);
for ($y = 0; $y < 512; $y++) {
    for ($x = 0; $x < 512; $x++) {
        $alpha = (imagecolorat($source, $x, $y) >> 24) & 0x7F;
        imagesetpixel($white, $x, $y, imagecolorallocatealpha($white, 255, 255, 255, $alpha));
    }
}

/**
 * El cuadro rojo con la liebre a 1024 px. `$radius` en px de 1024 (0 = cuadro sin redondear, para Apple, que redondea
 * solo); `$inset` es el margen de la liebre dentro del cuadro.
 */
function master(GdImage $white, int $radius, int $inset): GdImage
{
    $size = 1024;
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);

    $red = imagecolorallocate($canvas, 0xC2, 0x20, 0x1E);
    if ($radius === 0) {
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $red);
    } else {
        imagefilledrectangle($canvas, $radius, 0, $size - 1 - $radius, $size - 1, $red);
        imagefilledrectangle($canvas, 0, $radius, $size - 1, $size - 1 - $radius, $red);
        foreach ([[$radius, $radius], [$size - 1 - $radius, $radius], [$radius, $size - 1 - $radius], [$size - 1 - $radius, $size - 1 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($canvas, $cx, $cy, $radius * 2, $radius * 2, $red);
        }
    }

    $inner = $size - 2 * $inset;
    imagecopyresampled($canvas, $white, $inset, $inset, 0, 0, $inner, $inner, 512, 512);

    return $canvas;
}

function scaled(GdImage $master, int $size): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagecopyresampled($image, $master, 0, 0, 0, 0, $size, $size, 1024, 1024);

    return $image;
}

function png(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

// Pestaña: esquinas apenas redondeadas y la liebre grande para que se lea a 16 px.
$tab = master($white, 120, 48);
$entries = [];
foreach ([16, 32, 48] as $size) {
    $entries[$size] = png(scaled($tab, $size));
}

// ICO con imágenes PNG (Windows Vista en adelante y todos los navegadores actuales).
$count = count($entries);
$ico = pack('vvv', 0, 1, $count);
$offset = 6 + 16 * $count;
$data = '';
foreach ($entries as $size => $bytes) {
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($bytes), $offset);
    $offset += strlen($bytes);
    $data .= $bytes;
}
file_put_contents("{$root}/public/favicon.ico", $ico.$data);

// Apple: cuadro completo (iOS redondea las esquinas) con más aire alrededor de la liebre.
file_put_contents("{$root}/public/apple-touch-icon.png", png(scaled(master($white, 0, 150), 180)));

echo 'favicon.ico: '.filesize("{$root}/public/favicon.ico").' bytes; apple-touch-icon.png: '.filesize("{$root}/public/apple-touch-icon.png")." bytes\n";
