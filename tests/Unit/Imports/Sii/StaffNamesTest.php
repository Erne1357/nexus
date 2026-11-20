<?php

use App\Modules\Imports\Sii\StaffNames;

/*
| Búsqueda del RFC de un titular por su nombre (R-V2-6): sin acentos ni puntuación, todas sus palabras dentro del
| nombre del SII, y exactamente una persona o nada. Datos sintéticos.
*/

beforeEach(function () {
    $this->names = new StaffNames(
        [
            ['rfc' => 'PERJ800101AA1', 'nombre_empleado' => 'JOSÉ LUIS', 'apellidos_empleado' => 'PÉREZ NÚÑEZ'],
            ['rfc' => 'LOPA800101AA1', 'nombre_empleado' => 'ANA', 'apellidos_empleado' => 'LOPEZ RUIZ'],
            ['rfc' => 'LOPA900202BB2', 'nombre_empleado' => 'ANA', 'apellidos_empleado' => 'LOPEZ SOTO'],
        ],
        [
            ['rfc' => 'GOLM800101AA1', 'nombre_empleado' => 'MARÍA', 'apellidop' => 'GÓMEZ', 'apellidom' => 'LUNA'],
            ['rfc' => 'PERJ800101AA1', 'nombre_empleado' => 'JOSE LUIS', 'apellidop' => 'PEREZ', 'apellidom' => 'NUNEZ'],
        ],
    );
});

it('encuentra el RFC sin acentos, sin puntuación y con mayúsculas o minúsculas', function () {
    expect($this->names->rfcFor('Gómez,  Luna. María'))->toBe('GOLM800101AA1')
        ->and($this->names->rfcFor('jose luis perez nuñez'))->toBe('PERJ800101AA1');
});

it('una persona en docentes y en docentes2 cuenta una sola vez', function () {
    expect($this->names->rfcFor('José Luis Pérez'))->toBe('PERJ800101AA1');
});

it('un nombre que no está o que coincide con varias personas da nulo', function () {
    expect($this->names->rfcFor('Pedro Inexistente'))->toBeNull()
        ->and($this->names->rfcFor('Ana López'))->toBeNull()
        ->and($this->names->rfcFor('Ana López Soto'))->toBe('LOPA900202BB2')
        ->and($this->names->rfcFor('  . '))->toBeNull();
});
