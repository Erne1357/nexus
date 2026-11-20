<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Actions\SyncCampusMap;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\CampusMapStep;
use App\Modules\Imports\Sii\Steps\RoomsStep;
use Illuminate\Support\Facades\File;

/*
| Paso `mapa` de imports:migrate-sii (1.24.2): corre SyncCampusMap después de `aulas`, para que una corrida nueva no
| deshaga la normalización con el mapa. Sincroniza en una corrida real y no escribe en un ensayo.
*/

function campusMapStepRun(bool $dry): array
{
    $dir = sys_get_temp_dir().'/sii-'.bin2hex(random_bytes(6));
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $dir);

    try {
        return [...app(CampusMapStep::class)->batches(new SiiMigration(SiiExport::open($dir), $dir, dryRun: $dry))];
    } finally {
        File::deleteDirectory($dir);
    }
}

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $building = Building::factory()->create(['campus_id' => $this->c1->id, 'code' => 'U']);
    Room::factory()->create(['building_id' => $building->id, 'code' => '701']);
    Room::factory()->create(['building_id' => $building->id, 'code' => 'ZZ9']);
});

it('va justo después de aulas en SII_STEPS y se llama mapa', function () {
    $steps = ImportsServiceProvider::SII_STEPS;

    expect($steps[array_search(RoomsStep::class, $steps, true) + 1])->toBe(CampusMapStep::class)
        ->and((new CampusMapStep(app(SyncCampusMap::class)))->name())->toBe('mapa');
});

it('en una corrida real sincroniza con el mapa y reporta salones creados y movidos con una nota de los sin cruzar', function () {
    [$report] = campusMapStepRun(dry: false);

    expect(Room::query()->where('code', '701')->firstOrFail()->building->code)->toBe('SC')
        ->and($report->template)->toBe('campus_map')->and($report->errors)->toBe(0)
        ->and($report->created)->toBeGreaterThan(100)->and($report->changed)->toBe(1)
        ->and(implode(' ', $report->notes))->toContain('1 salones del SII sin lugar en el mapa')->not->toContain('ZZ9');
});

it('en un ensayo no escribe nada y reporta lo que haría', function () {
    $buildings = Building::count();
    $rooms = Room::count();

    [$report] = campusMapStepRun(dry: true);

    expect(Building::count())->toBe($buildings)->and(Room::count())->toBe($rooms)
        ->and(Room::query()->whereNotNull('map_key')->count())->toBe(0)
        ->and($report->created)->toBeGreaterThan(100)->and($report->changed)->toBe(1);
});
