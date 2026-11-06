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
    [PositionKind::Apoyo, null],
]);

it('asignatura y apoyo aceptan de 1 a 40 horas', function (PositionKind $kind) {
    expect($kind->allows(1))->toBeTrue()
        ->and($kind->allows(40))->toBeTrue()
        ->and($kind->allows(0))->toBeFalse()
        ->and($kind->allows(41))->toBeFalse();
})->with([PositionKind::Asignatura, PositionKind::Apoyo]);

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
        ->and(PositionKind::Apoyo->label())->toBe('Apoyo y asistencia')
        ->and(TeacherDegree::Licenciatura->label())->toBe('Licenciatura')
        ->and(TeacherDegree::Especialidad->label())->toBe('Especialidad')
        ->and(TeacherDegree::Maestria->label())->toBe('Maestría')
        ->and(TeacherDegree::Doctorado->label())->toBe('Doctorado');
});
