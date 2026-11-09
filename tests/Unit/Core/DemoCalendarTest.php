<?php

use App\Modules\Core\Demo\DemoCalendar;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\Stage;
use Carbon\CarbonImmutable;

/*
| El calendario de 20271 que deja la demo en cada punto: la etapa del punto empezó ayer, las anteriores ya
| terminaron y las siguientes vienen después, una tras otra, con las duraciones del calendario demo.
*/

it('pone en curso la etapa del punto', function () {
    $windows = DemoCalendar::windows(DemoCheckpoint::Staffing, CarbonImmutable::parse('2026-11-09'));

    expect($windows['staffing'])->toBe(['starts_on' => '2026-11-08', 'ends_on' => '2026-11-19'])
        ->and($windows['scheduling']['ends_on'])->toBe('2026-11-07')
        ->and($windows['scheduling']['starts_on'])->toBe('2026-10-27')
        ->and($windows['rooms'])->toBe(['starts_on' => '2026-11-20', 'ends_on' => '2026-11-24'])
        ->and(array_keys($windows))->toBe(array_map(fn (Stage $stage): string => $stage->value, Stage::cases()));
});

it('con catálogos la validación todavía no empieza', function () {
    $windows = DemoCalendar::windows(DemoCheckpoint::Catalogs, CarbonImmutable::parse('2026-11-09'));

    expect($windows['catalog_validation'])->toBe(['starts_on' => '2026-11-12', 'ends_on' => '2026-11-16']);
});

it('las ventanas van una tras otra, sin huecos ni traslapes, en todos los puntos', function (DemoCheckpoint $checkpoint) {
    $previousEnd = null;

    foreach (DemoCalendar::windows($checkpoint, CarbonImmutable::parse('2026-11-09')) as $window) {
        expect($window['starts_on'] <= $window['ends_on'])->toBeTrue();

        if ($previousEnd !== null) {
            expect($window['starts_on'])->toBe(CarbonImmutable::parse($previousEnd)->addDay()->toDateString());
        }

        $previousEnd = $window['ends_on'];
    }
})->with(fn (): array => DemoCheckpoint::staged());

it('todo deja el calendario como esté', function () {
    DemoCalendar::windows(DemoCheckpoint::All, CarbonImmutable::parse('2026-11-09'));
})->throws(LogicException::class);
