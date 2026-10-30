<?php

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\TeacherDegree;

it('cada tipo de plaza tiene sus horas fijas (S3)', function (PositionKind $kind, ?int $hours) {
    expect($kind->fixedHours())->toBe($hours);
})->with([
    [PositionKind::TiempoCompleto, 40],
    [PositionKind::TresCuartos, 30],
    [PositionKind::MedioTiempo, 20],
    [PositionKind::Asignatura, null],
    [PositionKind::Honorarios, 0],
]);

it('asignatura acepta de 1 a 40 horas', function () {
    expect(PositionKind::Asignatura->allows(1))->toBeTrue()
        ->and(PositionKind::Asignatura->allows(40))->toBeTrue()
        ->and(PositionKind::Asignatura->allows(0))->toBeFalse()
        ->and(PositionKind::Asignatura->allows(41))->toBeFalse();
});

it('los tipos fijos solo aceptan sus horas y honorarios solo 0', function () {
    expect(PositionKind::TiempoCompleto->allows(40))->toBeTrue()
        ->and(PositionKind::TiempoCompleto->allows(39))->toBeFalse()
        ->and(PositionKind::Honorarios->allows(0))->toBeTrue()
        ->and(PositionKind::Honorarios->allows(1))->toBeFalse();
});

it('cada tipo de plaza y cada grado tienen su etiqueta', function () {
    expect(PositionKind::TiempoCompleto->label())->toBe('Tiempo completo')
        ->and(PositionKind::TresCuartos->label())->toBe('Tres cuartos')
        ->and(PositionKind::MedioTiempo->label())->toBe('Medio tiempo')
        ->and(PositionKind::Asignatura->label())->toBe('Asignatura')
        ->and(PositionKind::Honorarios->label())->toBe('Honorarios')
        ->and(TeacherDegree::Licenciatura->label())->toBe('Licenciatura')
        ->and(TeacherDegree::Especialidad->label())->toBe('Especialidad')
        ->and(TeacherDegree::Maestria->label())->toBe('Maestría')
        ->and(TeacherDegree::Doctorado->label())->toBe('Doctorado');
});
