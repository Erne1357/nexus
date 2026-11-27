<?php

use App\Modules\Core\Support\SiiNip;

/*
| AC-11: la exportación del SII quitó los ceros a la izquierda del NIP; uno de 1 a 3 dígitos se completa con ceros a 4.
*/

it('AC-11: completa con ceros a 4 dígitos un NIP de 1 a 3 dígitos', function (string $nip, string $normalized) {
    expect(SiiNip::normalize($nip))->toBe($normalized);
})->with([
    'un dígito' => ['7', '0007'],
    'dos dígitos' => ['42', '0042'],
    'tres dígitos' => ['123', '0123'],
]);

it('AC-11: deja tal cual un NIP de 4 o más dígitos y lo que no es solo dígitos', function (string $nip) {
    expect(SiiNip::normalize($nip))->toBe($nip);
})->with(['0123', '9876', '12345', '', 'abc', '12a', ' 12', '١٢٣']);
