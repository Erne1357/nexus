<?php

use App\Modules\Core\Models\User;

/**
 * El ícono de Nexus (tarea 1.79): la liebre blanca en el cuadro rojo Tec (DESIGN.md, «La liebre»), en `public/favicon.ico`
 * (16, 32 y 48 px) y `public/apple-touch-icon.png` (180 px), enlazados desde el <head> de cada layout.
 */

/**
 * Los tamaños de las imágenes de un .ico, leídos de su directorio.
 *
 * @return list<int>
 */
function icoSizes(string $bytes): array
{
    $header = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));
    expect($header['reserved'])->toBe(0)->and($header['type'])->toBe(1);

    $sizes = [];
    for ($i = 0; $i < $header['count']; $i++) {
        $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', substr($bytes, 6 + 16 * $i, 16));
        expect(substr($bytes, $entry['offset'], 8))->toBe("\x89PNG\r\n\x1a\n")
            ->and($entry['offset'] + $entry['length'])->toBeLessThanOrEqual(strlen($bytes));
        $sizes[] = $entry['width'];
    }

    return $sizes;
}

it('el favicon.ico trae la liebre en 16, 32 y 48 px, y el ícono de Apple mide 180 px', function () {
    $ico = file_get_contents(public_path('favicon.ico'));
    [$width, $height] = getimagesize(public_path('apple-touch-icon.png'));

    expect(icoSizes($ico))->toBe([16, 32, 48])
        ->and([$width, $height])->toBe([180, 180]);
});

it('el login, las pantallas con sesión y la consulta pública enlazan el ícono', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="16x16 32x32 48x48">', false)
        ->assertSee('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'">', false);

    $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertOk()
        ->assertSee('rel="icon"', false);

    auth()->logout();
    $this->get(route('published.index'))->assertOk()->assertSee('rel="icon"', false);
});
