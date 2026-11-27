<?php

use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Config\Repository;

function defaultPasswordWith(mixed $value): DefaultPassword
{
    return new DefaultPassword(new Repository(['auth' => ['default_password' => $value]]));
}

it('lanza un error claro si NEXUS_DEFAULT_PASSWORD no está definida', function (mixed $value) {
    expect(fn () => defaultPasswordWith($value)->value())
        ->toThrow(RuntimeException::class, 'Define NEXUS_DEFAULT_PASSWORD');
})->with([null, '']);

it('entrega la contraseña por defecto configurada', function () {
    expect(defaultPasswordWith('Prueba#2026')->value())->toBe('Prueba#2026');
});

it('reconoce la contraseña por defecto y rechaza cualquier otra', function () {
    $default = defaultPasswordWith('Prueba#2026');

    expect($default->matches('Prueba#2026'))->toBeTrue()
        ->and($default->matches('prueba#2026'))->toBeFalse()
        ->and($default->matches(''))->toBeFalse();
});

it('no reconoce nada como la de defecto si no está definida', function () {
    expect(defaultPasswordWith(null)->matches(''))->toBeFalse();
});
