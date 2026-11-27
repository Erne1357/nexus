<?php

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Support\AssignmentTimeline;
use Carbon\CarbonImmutable;

/*
| La línea de vigencias de una persona: del 1 de enero del primer año al 31 de diciembre del último fin (o del año de
| hoy), con una barra por nombramiento y la marca de hoy. Uno sin fin llega al borde.
*/

it('acomoda cada nombramiento por años, con hoy marcado', function () {
    $timeline = AssignmentTimeline::build([
        ['label' => 'Coordinador', 'from' => CarbonImmutable::parse('2025-01-01'), 'to' => CarbonImmutable::parse('2025-12-31'), 'status' => AssignmentStatus::Ended],
        ['label' => 'Jefe', 'from' => CarbonImmutable::parse('2026-07-01'), 'to' => CarbonImmutable::parse('2026-12-31'), 'status' => AssignmentStatus::Current],
        ['label' => 'Docente', 'from' => CarbonImmutable::parse('2025-07-01'), 'to' => null, 'status' => AssignmentStatus::Current],
    ], CarbonImmutable::parse('2026-07-01'));

    // 2025-01-01 a 2026-12-31: 730 días.
    expect($timeline->hasRange())->toBeTrue()
        ->and($timeline->years)->toBe([['label' => '2025', 'left' => 0.0], ['label' => '2026', 'left' => 365 / 730 * 100]])
        ->and($timeline->today)->toBe((546 + 0.5) / 730 * 100)
        ->and($timeline->rows[0])->toBe(['label' => 'Coordinador', 'status' => AssignmentStatus::Ended, 'left' => 0.0, 'width' => 365 / 730 * 100, 'open' => false])
        ->and($timeline->rows[1])->toBe(['label' => 'Jefe', 'status' => AssignmentStatus::Current, 'left' => 546 / 730 * 100, 'width' => 184 / 730 * 100, 'open' => false])
        ->and($timeline->rows[2]['open'])->toBeTrue()
        ->and($timeline->rows[2]['left'])->toBe(181 / 730 * 100)
        ->and($timeline->rows[2]['width'])->toBe(549 / 730 * 100);
});

it('sin nombramientos no hay rango', function () {
    $timeline = AssignmentTimeline::build([], CarbonImmutable::parse('2026-07-01'));

    expect($timeline->hasRange())->toBeFalse()
        ->and($timeline->rows)->toBe([])
        ->and($timeline->years)->toBe([])
        ->and($timeline->today)->toBeNull();
});

it('rotula a lo más seis años, de 1, 2 o 5 en 5 según el rango', function () {
    $span = fn (int $from, int $to) => AssignmentTimeline::build([
        ['label' => 'X', 'from' => CarbonImmutable::create($from, 3, 1), 'to' => CarbonImmutable::create($to, 3, 1), 'status' => AssignmentStatus::Current],
    ], CarbonImmutable::create($to, 6, 1));

    expect(array_column($span(2024, 2026)->years, 'label'))->toBe(['2024', '2025', '2026'])
        ->and(array_column($span(2015, 2027)->years, 'label'))->toBe(['2015', '2020', '2025'])
        ->and(array_column($span(2019, 2027)->years, 'label'))->toBe(['2019', '2021', '2023', '2025', '2027'])
        ->and(count($span(2015, 2027)->years))->toBeLessThanOrEqual(6)
        ->and(count($span(2000, 2027)->years))->toBeLessThanOrEqual(6);
});
