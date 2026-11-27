<?php

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Rules\TeachingHours;

/*
| PL-01: horas frente a grupo por plaza (Reglamento Interior de Trabajo del Personal Docente de los Institutos
| Tecnológicos, arts. 143, 144, 146, 149 y 155). La regla es pura. El margen de ±2 (art. 147) es de LoadBand.
*/

function plaza(PositionKind $kind, ?string $category = null, ?int $hours = null): array
{
    return ['kind' => $kind, 'category' => $category, 'base_hours' => $hours ?? $kind->fixedHours() ?? 0];
}

it('PL-01: la tabla de carrera por categoría y jornada (arts. 143, 144 y 146)', function (string $category, int $tc, int $tq, int $mt) {
    expect(TeachingHours::forPositions([plaza(PositionKind::TiempoCompleto, $category)]))->toBe($tc)
        ->and(TeachingHours::forPositions([plaza(PositionKind::TresCuartos, $category)]))->toBe($tq)
        ->and(TeachingHours::forPositions([plaza(PositionKind::MedioTiempo, $category)]))->toBe($mt);
})->with([
    'titular A' => ['E3813', 22, 16, 12],
    'titular C' => ['E3817', 22, 16, 12],
    'asociado A' => ['E3807', 24, 18, 12],
    'asociado C' => ['E3811', 24, 18, 12],
    'técnico docente asociado' => ['E3837', 36, 28, 18],
    'técnico docente titular' => ['E3843', 36, 28, 18],
    'técnico docente titular 75' => ['E3875', 36, 28, 18],
    'investigador titular' => ['E3859', 12, 10, 6],
    'investigador titular C' => ['E3863', 12, 10, 6],
]);

it('PL-01 (supuesto por confirmar): una plaza de carrera sin categoría o con una desconocida se mide como titular', function () {
    expect(TeachingHours::forPositions([plaza(PositionKind::TiempoCompleto)]))->toBe(22)
        ->and(TeachingHours::forPositions([plaza(PositionKind::TresCuartos, 'E3799')]))->toBe(16)
        ->and(TeachingHours::forPositions([plaza(PositionKind::MedioTiempo, null)]))->toBe(12);
});

it('PL-01: asignatura sola da todas sus horas (arts. 140 y 155)', function () {
    expect(TeachingHours::forPositions([plaza(PositionKind::Asignatura, 'E3519', 12)]))->toBe(12)
        ->and(TeachingHours::forPositions([plaza(PositionKind::Asignatura, 'E3519', 7), plaza(PositionKind::Asignatura, 'E3521', 5)]))->toBe(12);
});

it('PL-01: las horas de asignatura sobre una plaza de carrera siguen la tabla del art. 149', function (int $hours, int $expected) {
    expect(TeachingHours::forPositions([plaza(PositionKind::MedioTiempo, 'E3613'), plaza(PositionKind::Asignatura, 'E3519', $hours)]))->toBe(12 + $expected)
        ->and(TeachingHours::onCareer($hours))->toBe($expected);
})->with([[1, 1], [2, 1], [3, 2], [4, 2], [5, 3], [6, 3], [7, 4], [8, 4], [9, 5], 'de 10 en adelante la mitad hacia arriba (supuesto por confirmar)' => [10, 5], [11, 6], [20, 10]]);

it('PL-01: mezcla de tiempo completo y asignatura', function () {
    expect(TeachingHours::forPositions([plaza(PositionKind::TiempoCompleto, 'E3817'), plaza(PositionKind::Asignatura, 'E3519', 6)]))->toBe(22 + 3);
});

it('PL-01 (supuesto por confirmar): apoyo y honorarios dan 0', function () {
    expect(TeachingHours::forPositions([plaza(PositionKind::Apoyo, 'IA08', 36)]))->toBe(0)
        ->and(TeachingHours::forPositions([plaza(PositionKind::Honorarios)]))->toBe(0)
        ->and(TeachingHours::forPositions([plaza(PositionKind::Apoyo, 'IA08', 36), plaza(PositionKind::Asignatura, 'E3519', 8)]))->toBe(8)
        ->and(TeachingHours::forPositions([]))->toBe(0);
});

it('PL-01 (supuesto por confirmar): sin plazas en Nexus, las horas del CSV pasan 40 a 22, 30 a 16, 20 a 12 y otro n a n', function () {
    expect(TeachingHours::fromCsvHours(40))->toBe(22)
        ->and(TeachingHours::fromCsvHours(30))->toBe(16)
        ->and(TeachingHours::fromCsvHours(20))->toBe(12)
        ->and(TeachingHours::fromCsvHours(8))->toBe(8)
        ->and(TeachingHours::fromCsvHours(0))->toBe(0);
});
