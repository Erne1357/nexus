<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiCuratedMismatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\RoomsStep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `aulas` de imports:migrate-sii: un lote `rooms` por campus con las aulas del curado marcadas `incluir=si`.
| El curado solo trae las aulas usadas desde 20251, así que un aula del SII sin curado se reporta sin detener; un
| aula curada que el SII no trae sí es error de contrato. Todo con datos sintéticos.
*/

const ROOMS_STEP_CURATED_HEADER = 'aula,incluir,campus,edificio,nombre,tipo,capacidad,departamento,sesiones_desde_20251,nota';

/**
 * @param  list<array{0: string, 1?: string, 2?: string}>  $rooms  aula, permite_cruce y estatus del SII
 */
function writeSiiRooms(string $dir, array $rooms): void
{
    SiiFixtures::write($dir.'/aulas.csv', SiiFile::Aulas, array_map(fn (array $room): string => SiiFixtures::line(SiiFile::Aulas, [
        'aula' => "'{$room[0]}'",
        'permite_cruce' => "'".($room[1] ?? 'N')."'",
        'estatus' => "'".($room[2] ?? 'A')."'",
    ]), $rooms));
}

/**
 * @param  list<string>  $lines  renglones del curado, sin encabezado
 */
function writeRoomsCurated(string $dir, array $lines): void
{
    File::put($dir.'/aulas.csv', ROOMS_STEP_CURATED_HEADER."\n".implode("\n", $lines)."\n");
}

/**
 * @return array<string, array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>, campus: string|null}>
 */
function roomsStepRun(string $dir, string $curated): array
{
    $out = [];

    foreach ((new RoomsStep)->batches(new SiiMigration(SiiExport::open($dir), $curated)) as $batch) {
        $out[$batch->file] = ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents, 'campus' => $batch->campus];
    }

    return $out;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sii-'.uniqid();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    $this->curated = sys_get_temp_dir().'/sii-curated-'.uniqid();
    File::makeDirectory($this->curated, 0775, true);

    writeSiiRooms($this->dir, [['A1'], ['A2'], ['L1', 'S'], ['B1', 'N', 'I'], ['X1'], ['X2', 'N', 'I'], ['EAD1'], [' 910'], ['910']]);
    writeRoomsCurated($this->curated, [
        'A1,si,C1,ED1,,aula,30,,5,',
        'A2,si,C2,ED2,Aula dos,aula,25,110100,3,',
        'L1,si,C1,ED1,Laboratorio uno,laboratorio,20,,9,',
        'B1,si,C2,ED2,,auditorio,100,,2,',
        'EAD1,no,C1,100,,aula,35,,1,virtual',
        '910,si,C1,ED1,,aula,24,,4,',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->curated);
});

it('crea un lote de aulas por campus', function () {
    $run = roomsStepRun($this->dir, $this->curated);

    expect(array_keys($run))->toBe(['aulas-C1.csv', 'aulas-C2.csv'])
        ->and($run['aulas-C1.csv']['campus'])->toBe('C1')
        ->and($run['aulas-C2.csv']['campus'])->toBe('C2')
        ->and(array_column($run['aulas-C1.csv']['rows'], 'code'))->toBe(['A1', 'L1', '910'])
        ->and($run['aulas-C2.csv']['rows'][0])->toBe([
            'building' => 'ED2', 'code' => 'A2', 'name' => 'Aula dos', 'type' => 'aula', 'capacity' => '25', 'department' => '110100',
        ]);
});

it('omite las aulas no incluidas y avisa cuántas son por campus', function () {
    $run = roomsStepRun($this->dir, $this->curated);
    $messages = array_column($run['aulas-C1.csv']['incidents'], 'message');

    expect(array_column($run['aulas-C1.csv']['rows'], 'code'))->not->toContain('EAD1')
        ->and(collect($messages)->contains(fn (string $m): bool => str_contains($m, '1 aula') && str_contains($m, 'EAD1')))->toBeTrue();
});

it('un aula del SII sin curado se reporta sin detener', function () {
    $run = roomsStepRun($this->dir, $this->curated);
    $incidents = [...$run['aulas-C1.csv']['incidents'], ...$run['aulas-C2.csv']['incidents']];
    $aggregated = collect($incidents)->filter(fn (array $i): bool => str_contains($i['message'], 'no están en el curado'));

    expect($aggregated)->toHaveCount(1)
        ->and($aggregated->first()['level'])->toBe('warning')
        ->and($aggregated->first()['message'])->toContain('2 aulas del SII no están en el curado')
        ->and(collect($incidents)->where('ref', 'X1')->count())->toBe(1)
        ->and(collect($incidents)->where('ref', 'X2')->count())->toBe(0);
});

it('un aula del curado que no existe en el SII detiene la migración', function () {
    writeRoomsCurated($this->curated, ['A1,si,C1,ED1,,aula,30,,5,', 'FANTASMA,si,C1,ED1,,aula,30,,5,']);

    expect(fn () => roomsStepRun($this->dir, $this->curated))->toThrow(SiiCuratedMismatch::class, 'FANTASMA');
});

it('un renglón curado con valores inválidos detiene la migración nombrando el aula', function (string $line) {
    writeRoomsCurated($this->curated, [$line]);

    expect(fn () => roomsStepRun($this->dir, $this->curated))->toThrow(SiiCuratedMismatch::class, 'A1');
})->with([
    'incluir' => 'A1,quizá,C1,ED1,,aula,30,,5,',
    'campus' => 'A1,si,C9,ED1,,aula,30,,5,',
    'tipo' => 'A1,si,C1,ED1,,cancha,30,,5,',
    'capacidad' => 'A1,si,C1,ED1,,aula,0,,5,',
]);

it('avisa cuando el SII permite cruces', function () {
    $run = roomsStepRun($this->dir, $this->curated);
    $warning = collect($run['aulas-C1.csv']['incidents'])->firstWhere('ref', 'L1');

    expect($warning['level'])->toBe('warning')
        ->and($warning['message'])->toContain('el SII permite cruces en esta aula; Nexus no: revisar');
});

it('avisa de un aula incluida que está inactiva en el SII y la migra', function () {
    $run = roomsStepRun($this->dir, $this->curated);
    $warning = collect($run['aulas-C2.csv']['incidents'])->firstWhere('ref', 'B1');

    expect($warning['message'])->toContain('inactiva en el SII')
        ->and(array_column($run['aulas-C2.csv']['rows'], 'code'))->toContain('B1');
});

it('junta las claves que chocan al recortar espacios y avisa una vez', function () {
    $run = roomsStepRun($this->dir, $this->curated);
    $warnings = collect([...$run['aulas-C1.csv']['incidents'], ...$run['aulas-C2.csv']['incidents']])->where('ref', '910');

    expect($warnings)->toHaveCount(1)
        ->and(array_column($run['aulas-C1.csv']['rows'], 'code'))->toContain('910');
});

it('dos aulas curadas incluidas del mismo campus que chocan al normalizar detienen la migración nombrando ambas', function () {
    writeSiiRooms($this->dir, [['LM1'], ['L M 1']]);
    writeRoomsCurated($this->curated, ['LM1,si,C1,D,,aula,30,,5,', 'L M 1,si,C1,D2,,aula,35,,8,']);

    expect(fn () => roomsStepRun($this->dir, $this->curated))->toThrow(SiiCuratedMismatch::class, 'LM1 y L M 1');
});

it('una aula curada con otra grafía se empareja con la del SII al normalizar', function () {
    writeSiiRooms($this->dir, [['Lab CB']]);
    writeRoomsCurated($this->curated, ['LabCB,si,C1,D,,laboratorio,30,,5,']);

    $run = roomsStepRun($this->dir, $this->curated);

    expect(array_column($run['aulas-C1.csv']['rows'], 'code'))->toBe(['LABCB']);
});

it('no entrega lotes vacíos de un campus sin aulas incluidas', function () {
    writeSiiRooms($this->dir, [['A1'], ['X1']]);
    writeRoomsCurated($this->curated, ['A1,si,C2,ED2,,aula,30,,5,']);

    $run = roomsStepRun($this->dir, $this->curated);

    expect(array_keys($run))->toBe(['aulas-C2.csv'])
        ->and(collect($run['aulas-C2.csv']['incidents'])->contains(fn (array $i): bool => str_contains($i['message'], 'no están en el curado') || str_contains($i['message'], 'no está en el curado')))->toBeTrue();
});

it('sin aulas incluidas entrega un lote vacío de C1 que lleva las incidencias', function () {
    writeSiiRooms($this->dir, [['A1'], ['X1']]);
    writeRoomsCurated($this->curated, ['A1,no,C2,ED2,,aula,30,,5,']);

    $run = roomsStepRun($this->dir, $this->curated);

    expect(array_keys($run))->toBe(['aulas-C1.csv'])
        ->and($run['aulas-C1.csv']['rows'])->toBe([])
        ->and($run['aulas-C1.csv']['incidents'])->not->toBe([]);
});

it('migra las aulas de punta a punta y crea los edificios', function () {
    Storage::fake('imports');
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);
    User::factory()->admin()->create(['username' => 'migrador']);
    writeRoomsCurated($this->curated, [
        'A1,si,C1,ED1,,aula,30,,5,', 'A2,si,C2,ED2,Aula dos,aula,25,,3,', 'L1,si,C1,ED1,Lab uno,laboratorio,20,,9,',
        'B1,si,C2,ED2,,auditorio,100,,2,', 'EAD1,no,C1,100,,aula,35,,1,', '910,si,C1,ED1,,aula,24,,4,',
    ]);

    app()->instance('sii.steps', [RoomsStep::class]);
    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($this->curated);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $rooms = Room::query()->with('building.campus')->orderBy('code')->get();

    expect($rooms->pluck('code')->all())->toBe(['910', 'A1', 'A2', 'B1', 'L1'])
        ->and($rooms->firstWhere('code', 'A2')->building->campus->code)->toBe('C2')
        ->and($rooms->firstWhere('code', 'A2')->name)->toBe('Aula dos')
        ->and($rooms->firstWhere('code', 'B1')->type->value)->toBe('auditorio')
        ->and($rooms->every(fn (Room $room): bool => $room->is_active))->toBeTrue()
        ->and(ImportBatch::query()->where('template', 'rooms')->sum('error_rows'))->toBe(0)
        ->and(file_get_contents($this->dir.'/_incidencias.csv'))->toContain('no están en el curado');
});
