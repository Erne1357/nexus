<?php

use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Rules\AvailabilityRanges;

/*
| Unión de bloques de disponibilidad (spec, "Pruebas": unión de bloques): las celdas marcadas de la cuadrícula se
| guardan como rangos. Los bloques contiguos del mismo tipo y del mismo día son un rango; un cambio de tipo, de día o
| un hueco empieza otro. El orden de las celdas no importa.
*/

function availabilityCell(int $day, string $startsAt, AvailabilityKind $kind = AvailabilityKind::Unavailable): array
{
    return ['day' => $day, 'starts_at' => $startsAt, 'kind' => $kind];
}

function availabilityRange(int $day, string $startsAt, string $endsAt, AvailabilityKind $kind = AvailabilityKind::Unavailable): array
{
    return ['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'kind' => $kind];
}

describe('merge', function () {
    it('une los bloques contiguos del mismo tipo en un rango', function () {
        $ranges = AvailabilityRanges::merge([
            availabilityCell(1, '07:00'),
            availabilityCell(1, '08:00'),
            availabilityCell(1, '09:00'),
        ], 60);

        expect($ranges)->toBe([availabilityRange(1, '07:00', '10:00')]);
    });

    it('no une tipos alternados', function () {
        $ranges = AvailabilityRanges::merge([
            availabilityCell(1, '07:00'),
            availabilityCell(1, '08:00', AvailabilityKind::Preferred),
            availabilityCell(1, '09:00'),
        ], 60);

        expect($ranges)->toBe([
            availabilityRange(1, '07:00', '08:00'),
            availabilityRange(1, '08:00', '09:00', AvailabilityKind::Preferred),
            availabilityRange(1, '09:00', '10:00'),
        ]);
    });

    it('no une la misma hora de días distintos ni el fin de un día con el inicio del siguiente', function () {
        $ranges = AvailabilityRanges::merge([
            availabilityCell(1, '20:00'),
            availabilityCell(2, '07:00'),
            availabilityCell(3, '07:00'),
        ], 60);

        expect($ranges)->toBe([
            availabilityRange(1, '20:00', '21:00'),
            availabilityRange(2, '07:00', '08:00'),
            availabilityRange(3, '07:00', '08:00'),
        ]);
    });

    it('no une bloques del mismo tipo con un hueco entre ellos', function () {
        $ranges = AvailabilityRanges::merge([availabilityCell(1, '07:00'), availabilityCell(1, '09:00')], 60);

        expect($ranges)->toBe([availabilityRange(1, '07:00', '08:00'), availabilityRange(1, '09:00', '10:00')]);
    });

    it('celdas desordenadas dan el mismo resultado', function () {
        $cells = [
            availabilityCell(2, '16:00'),
            availabilityCell(1, '09:00', AvailabilityKind::Preferred),
            availabilityCell(2, '17:00'),
            availabilityCell(1, '07:00'),
            availabilityCell(1, '10:00', AvailabilityKind::Preferred),
            availabilityCell(2, '15:00'),
        ];
        $shuffled = [$cells[3], $cells[5], $cells[0], $cells[4], $cells[1], $cells[2]];

        expect(AvailabilityRanges::merge($shuffled, 60))
            ->toBe(AvailabilityRanges::merge($cells, 60))
            ->toBe([
                availabilityRange(1, '07:00', '08:00'),
                availabilityRange(1, '09:00', '11:00', AvailabilityKind::Preferred),
                availabilityRange(2, '15:00', '18:00'),
            ]);
    });

    it('cada bloque dura lo que dice el perfil: 60 o 30 minutos', function () {
        expect(AvailabilityRanges::merge([availabilityCell(1, '07:00')], 60))->toBe([availabilityRange(1, '07:00', '08:00')])
            ->and(AvailabilityRanges::merge([availabilityCell(1, '07:00'), availabilityCell(1, '07:30')], 30))->toBe([availabilityRange(1, '07:00', '08:00')])
            ->and(AvailabilityRanges::merge([availabilityCell(1, '07:00'), availabilityCell(1, '08:00')], 30))->toBe([availabilityRange(1, '07:00', '07:30'), availabilityRange(1, '08:00', '08:30')]);
    });

    it('sin celdas no hay rangos', function () {
        expect(AvailabilityRanges::merge([], 60))->toBe([]);
    });

    it('rechaza una celda repetida', function () {
        AvailabilityRanges::merge([availabilityCell(1, '07:00'), availabilityCell(1, '07:00', AvailabilityKind::Preferred)], 60);
    })->throws(InvalidArgumentException::class);
});

describe('join', function () {
    it('une rangos contiguos del mismo tipo y día aunque no midan un bloque, y los ordena', function () {
        $ranges = AvailabilityRanges::join([
            availabilityRange(1, '21:00', '22:30'),
            availabilityRange(1, '20:00', '21:00'),
            availabilityRange(1, '07:00', '07:30', AvailabilityKind::Preferred),
            availabilityRange(6, '08:00', '09:00'),
        ]);

        expect($ranges)->toBe([
            availabilityRange(1, '07:00', '07:30', AvailabilityKind::Preferred),
            availabilityRange(1, '20:00', '22:30'),
            availabilityRange(6, '08:00', '09:00'),
        ]);
    });

    it('no une tipos distintos ni rangos con hueco', function () {
        $ranges = [
            availabilityRange(1, '07:00', '08:00'),
            availabilityRange(1, '08:00', '09:00', AvailabilityKind::Preferred),
            availabilityRange(1, '09:30', '10:00', AvailabilityKind::Preferred),
        ];

        expect(AvailabilityRanges::join($ranges))->toBe($ranges);
    });
});
