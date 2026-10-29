<?php

use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;

it('cada estatus de alumno tiene su etiqueta', function (StudentStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    [StudentStatus::Activo, 'Activo'],
    [StudentStatus::BajaTemporal, 'Baja temporal'],
    [StudentStatus::BajaDefinitiva, 'Baja definitiva'],
    [StudentStatus::Egresado, 'Egresado'],
]);

it('cada resultado del kardex tiene su etiqueta, y solo la aprobada aprueba la materia', function (KardexResult $result, string $label, bool $approves) {
    expect($result->label())->toBe($label)
        ->and($result->approves())->toBe($approves);
})->with([
    [KardexResult::Aprobada, 'Aprobada', true],
    [KardexResult::Reprobada, 'Reprobada', false],
    [KardexResult::Cursando, 'Cursando', false],
    [KardexResult::Baja, 'Baja', false],
]);
