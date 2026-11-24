<?php

use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;

const SEL_TZ = 'America/Ciudad_Juarez';

it('SEL-08: los turnos son consecutivos y pasan al siguiente día al llenar la ventana', function () {
    $start = CarbonImmutable::parse('2026-11-30 09:00', SEL_TZ);

    $slots = SelectionSlots::layout($start, '09:00', '14:00', [1, 2, 3, 4, 5], 30, 12);

    expect($slots)->toHaveCount(12)
        ->and($slots[0]['starts_at']->format('Y-m-d H:i'))->toBe('2026-11-30 09:00')
        ->and($slots[9]['ends_at']->format('Y-m-d H:i'))->toBe('2026-11-30 14:00')
        ->and($slots[10]['starts_at']->format('Y-m-d H:i'))->toBe('2026-12-01 09:00')
        ->and($slots[11]['starts_at']->format('Y-m-d H:i'))->toBe('2026-12-01 09:30')
        ->and($slots[11]['ends_at']->format('Y-m-d H:i'))->toBe('2026-12-01 10:00');
});

it('SEL-08: una ventana de 09:00 a 10:15 deja dos turnos por día', function () {
    $slots = SelectionSlots::layout(CarbonImmutable::parse('2026-11-30 09:00', SEL_TZ), '09:00', '10:15', [1, 2, 3, 4, 5], 30, 3);

    expect($slots[1]['ends_at']->format('H:i'))->toBe('10:00')
        ->and($slots[2]['starts_at']->format('Y-m-d H:i'))->toBe('2026-12-01 09:00');
});

it('SEL-08: un inicio en sábado pasa al lunes', function () {
    $slots = SelectionSlots::layout(CarbonImmutable::parse('2026-11-28 09:00', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30, 1);

    expect($slots[0]['starts_at']->format('Y-m-d H:i'))->toBe('2026-11-30 09:00');
});

it('SEL-08: el viernes lleno pasa al lunes y un inicio antes de la ventana arranca en ella', function () {
    $slots = SelectionSlots::layout(CarbonImmutable::parse('2026-12-04 13:30', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30, 2);
    expect($slots[1]['starts_at']->format('Y-m-d H:i'))->toBe('2026-12-07 09:00');

    $early = SelectionSlots::layout(CarbonImmutable::parse('2026-12-01 07:00', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30, 1);
    expect($early[0]['starts_at']->format('Y-m-d H:i'))->toBe('2026-12-01 09:00');
});

it('SEL-08: la segunda ronda arranca 30 minutos después de la primera, o al día siguiente si no cabe', function () {
    $outside = SelectionSlots::extraRoundStart(CarbonImmutable::parse('2026-11-30 13:30', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30);
    expect($outside->format('Y-m-d H:i'))->toBe('2026-12-01 09:00');

    $inside = SelectionSlots::extraRoundStart(CarbonImmutable::parse('2026-11-30 10:00', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30);
    expect($inside->format('Y-m-d H:i'))->toBe('2026-11-30 10:30');

    $between = SelectionSlots::extraRoundStart(CarbonImmutable::parse('2026-11-30 10:10', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30);
    expect($between->format('Y-m-d H:i'))->toBe('2026-11-30 11:00');
});

it('SEL-08: la segunda ronda redondea hacia arriba al siguiente turno si el fin trae segundos', function () {
    $start = SelectionSlots::extraRoundStart(CarbonImmutable::parse('2026-11-30 10:00:30', SEL_TZ), '09:00', '14:00', [1, 2, 3, 4, 5], 30);

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-11-30 11:00:00');
});
