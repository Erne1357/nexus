<?php

use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\Stage;

/*
| Los puntos de la demo (`php artisan demo:seed --hasta=`): cada uno es el inicio de una etapa del proceso, con lo
| anterior terminado. `todo` es la demo mezclada de siempre, no un punto del proceso.
*/

it('lista los puntos en el orden del proceso', function () {
    expect(DemoCheckpoint::staged())->toBe([
        DemoCheckpoint::Catalogs,
        DemoCheckpoint::CatalogValidation,
        DemoCheckpoint::Proposal,
        DemoCheckpoint::Scheduling,
        DemoCheckpoint::Staffing,
        DemoCheckpoint::Rooms,
        DemoCheckpoint::Closing,
    ])->and(DemoCheckpoint::upTo(DemoCheckpoint::Proposal))->toBe([
        DemoCheckpoint::Catalogs,
        DemoCheckpoint::CatalogValidation,
        DemoCheckpoint::Proposal,
    ])->and(DemoCheckpoint::upTo(DemoCheckpoint::Catalogs))->toBe([DemoCheckpoint::Catalogs]);
});

it('cada punto empieza la etapa de su nombre y lleva su número', function () {
    expect(DemoCheckpoint::Staffing->stage())->toBe(Stage::Staffing)
        ->and(DemoCheckpoint::CatalogValidation->stage())->toBe(Stage::CatalogValidation)
        ->and(DemoCheckpoint::Closing->stage())->toBe(Stage::Closing)
        ->and(DemoCheckpoint::Catalogs->stage())->toBeNull()
        ->and(DemoCheckpoint::All->stage())->toBeNull()
        ->and(DemoCheckpoint::Catalogs->number())->toBe(1)
        ->and(DemoCheckpoint::Closing->number())->toBe(7)
        ->and(DemoCheckpoint::All->number())->toBeNull()
        ->and(DemoCheckpoint::Staffing->label())->toBe('Empezando la asignación docente')
        ->and(DemoCheckpoint::tryFrom('asignacion'))->toBe(DemoCheckpoint::Staffing)
        ->and(DemoCheckpoint::tryFrom('asignación'))->toBeNull();
});

it('todo no es un punto del proceso', function () {
    DemoCheckpoint::upTo(DemoCheckpoint::All);
})->throws(LogicException::class);

it('cada volumen siembra sus campus', function () {
    expect(DemoVolume::Demo->campuses())->toBe(['C2'])
        ->and(DemoVolume::Massive->campuses())->toBe(['C1', 'C2'])
        ->and(DemoVolume::tryFrom('masivo'))->toBe(DemoVolume::Massive);
});
