<?php

use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\PositionsStep;
use App\Modules\Imports\Sii\Steps\TeachersStep;
use Illuminate\Support\Facades\File;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `plazas` de imports:migrate-sii: las plazas de `docentes2.claves_plazas` de cada docente migrado, una fila por
| tipo con las horas sumadas y sin vigencia (el SII no la trae). Las plazas de apoyo cuentan como cualquier otra
| (confirmado el 6 de noviembre de 2026). Datos sintéticos: RFC y claves inventados.
*/

/**
 * @return array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}
 */
function positionsStepRun(string $dir): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('department_for_area', fn (): array => [110100 => '110100']);
    [...(new TeachersStep)->batches($migration)];

    foreach ((new PositionsStep)->batches($migration) as $batch) {
        return ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }
}

/**
 * @return array<string, string>
 */
function positionsStaffRow(string $rfc, string $status = '01', string $nombramiento = 'D'): array
{
    return [
        'rfc' => $rfc, 'clave_area' => '110100', 'nombramiento' => $nombramiento, 'status_empleado' => $status,
        'nombre_empleado' => 'JUAN', 'apellidos_empleado' => 'PEREZ', 'correo_electronico' => '', 'inicio_plantel' => '000000',
    ];
}

/**
 * @return array<string, string>
 */
function positionsStaff2Row(string $rfc, string $plazas, string $hours): array
{
    return [
        'rfc' => $rfc, 'apellidop' => 'GOMEZ', 'apellidom' => 'LUNA', 'nombre_empleado' => 'MARIA', 'id_unidad_organica' => '110100',
        'fecha_ingreso_institucion' => '', 'claves_plazas' => $plazas, 'cantidad_horas_nombramiento' => $hours,
    ];
}

/**
 * @param  list<array{level: string, ref: string, message: string}>  $incidents
 * @return array<string, string> mensaje por `ref` de las incidencias de un renglón de docentes2
 */
function positionsLineIncidents(array $incidents): array
{
    return collect($incidents)->filter(fn (array $i): bool => str_starts_with($i['ref'], 'docentes2.csv:'))
        ->groupBy('ref')->map(fn ($group): string => $group->pluck('message')->implode(' | '))->all();
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    SiiFixtures::writeGroups($this->dir, []);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('emite una fila por tipo de plaza con las horas sumadas y sin vigencia', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101AB1')], [
        positionsStaff2Row('PRUE800101AB1', '1403 E371700.0100001 1403 E352504.0100002 1403 E352506.0100003', '40'),
    ]);

    $run = positionsStepRun($this->dir);

    expect($run['rows'])->toBe([
        ['rfc' => 'PRUE800101AB1', 'kind' => 'tres_cuartos', 'base_hours' => 30, 'valid_from' => '', 'valid_to' => ''],
        ['rfc' => 'PRUE800101AB1', 'kind' => 'asignatura', 'base_hours' => 10, 'valid_from' => '', 'valid_to' => ''],
    ])
        ->and(positionsLineIncidents($run['incidents']))->toBe([]);
});

it('una plaza de apoyo cuenta con sus 36 horas como cualquier otra plaza (confirmado el 6 de noviembre de 2026)', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101AB1')], [
        positionsStaff2Row('PRUE800101AB1', '1403 IA0801600.0100001 1403 E352506.0100002', '42'),
    ]);

    $run = positionsStepRun($this->dir);

    expect(array_map(fn (array $row): array => [$row['kind'], $row['base_hours']], $run['rows']))->toBe([['asignatura', 6], ['apoyo', 36]])
        ->and(positionsLineIncidents($run['incidents']))->toBe([]);
});

it('las plazas del RFC viejo (estatus 04) pasan al docente del RFC nuevo', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101OLD', '04'), positionsStaffRow('PRUE800101NEW')], [
        positionsStaff2Row('PRUE800101OLD', '1403 E381700.0100001', '40'),
    ]);

    expect(positionsStepRun($this->dir)['rows'])->toBe([
        ['rfc' => 'PRUE800101NEW', 'kind' => 'tiempo_completo', 'base_hours' => 40, 'valid_from' => '', 'valid_to' => ''],
    ]);
});

it('cuenta las plazas de quien no se migró y a los docentes activos sin plaza', function () {
    SiiFixtures::writeStaff($this->dir, [
        positionsStaffRow('PRUE800101AB1'), positionsStaffRow('PRUE800102AB1'), positionsStaffRow('ADMI800101AA1', '01', 'A'),
    ], [
        positionsStaff2Row('PRUE800101AB1', '1403 E381700.0100001', '40'),
        positionsStaff2Row('ADMI800101AA1', '1403 IA0801600.0100002', '36'),
    ]);
    SiiFixtures::writeGroups($this->dir, [['20263', 'M1', 'A', 'PRUE800102AB1']]);

    $run = positionsStepRun($this->dir);
    $messages = collect($run['incidents'])->pluck('message')->implode("\n");

    expect(array_column($run['rows'], 'rfc'))->toBe(['PRUE800101AB1'])
        ->and($messages)->toContain('1 renglones de docentes2 no son de un docente migrado')
        ->and($messages)->toContain('1 docentes activos no tienen plaza en docentes2');
});

it('señala por archivo y línea, sin RFC, las plazas que no cuadran, no se entienden o traen más de 40 horas', function () {
    SiiFixtures::writeStaff($this->dir, [
        positionsStaffRow('PRUE800101AB1'), positionsStaffRow('PRUE800102AB1'), positionsStaffRow('PRUE800103AB1'), positionsStaffRow('PRUE800104AB1'),
    ], [
        positionsStaff2Row('PRUE800101AB1', '1403 E352504.0100001', '8'),
        positionsStaff2Row('PRUE800102AB1', 'SIN CLAVE', '40'),
        positionsStaff2Row('PRUE800103AB1', '1403 E391700.0100002 1403 E381700.0100003 1403 E381500.0100004', '40'),
        positionsStaff2Row('PRUE800104AB1', '1403 E3525610000005', '6'),
    ]);

    $run = positionsStepRun($this->dir);
    $lines = positionsLineIncidents($run['incidents']);

    expect(array_keys($lines))->toBe(['docentes2.csv:2', 'docentes2.csv:3', 'docentes2.csv:4', 'docentes2.csv:5'])
        ->and($lines['docentes2.csv:2'])->toContain('suman 4 horas y el SII dice 8')
        ->and($lines['docentes2.csv:3'])->toContain('no se entiende')
        ->and($lines['docentes2.csv:4'])->toContain('E3917')->toContain('dos plazas de Tiempo completo')
        ->and($lines['docentes2.csv:5'])->toContain('trae 61 horas en la clave')
        ->and(collect($run['incidents'])->map(fn (array $i): string => $i['ref'].' '.$i['message'])->implode("\n"))->not->toContain('PRUE')
        ->and(collect($run['rows'])->where('rfc', 'PRUE800103AB1')->values()->all())->toBe([
            ['rfc' => 'PRUE800103AB1', 'kind' => 'tiempo_completo', 'base_hours' => 40, 'valid_from' => '', 'valid_to' => ''],
        ]);
});

it('avisa la parte de una clave que no se entiende aunque otra sí se migre', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101AB1')], [
        positionsStaff2Row('PRUE800101AB1', '1403 E352504.0100001, 1403 IT123400.0100002', '4'),
    ]);

    $run = positionsStepRun($this->dir);

    expect(array_column($run['rows'], 'base_hours'))->toBe([4])
        ->and(positionsLineIncidents($run['incidents']))->toBe(['docentes2.csv:2' => 'Parte de la clave de plaza de esta línea no se entiende: esa parte no se migra.']);
});

it('no migra un tipo cuyas plazas suman más de 40 horas y lo avisa por línea', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101AB1')], [
        positionsStaff2Row('PRUE800101AB1', '1403 IA0801600.0100001 1403 IS0700200.0100002 1403 E352504.0100003', '76'),
    ]);

    $run = positionsStepRun($this->dir);

    expect(array_map(fn (array $row): array => [$row['kind'], $row['base_hours']], $run['rows']))->toBe([['asignatura', 4]])
        ->and(positionsLineIncidents($run['incidents'])['docentes2.csv:2'])->toContain('Apoyo y asistencia de esta línea suman 72 horas');
});

it('si el RFC viejo y el nuevo están en docentes2, toma las plazas del renglón del RFC nuevo', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101OLD', '04'), positionsStaffRow('PRUE800101NEW')], [
        positionsStaff2Row('PRUE800101OLD', '', ''),
        positionsStaff2Row('PRUE800101NEW', '1403 E381700.0100001', '40'),
    ]);

    $run = positionsStepRun($this->dir);

    expect($run['rows'])->toBe([['rfc' => 'PRUE800101NEW', 'kind' => 'tiempo_completo', 'base_hours' => 40, 'valid_from' => '', 'valid_to' => '']])
        ->and(collect($run['incidents'])->pluck('message')->implode("\n"))->toContain('1 renglones de docentes2 repiten un docente');
});

it('compara la suma con las horas del SII aunque vengan con decimales', function () {
    SiiFixtures::writeStaff($this->dir, [positionsStaffRow('PRUE800101AB1')], [
        positionsStaff2Row('PRUE800101AB1', '1403 E361700.0100001', '40.00'),
    ]);

    expect(positionsLineIncidents(positionsStepRun($this->dir)['incidents']))->toBe([
        'docentes2.csv:2' => 'Las plazas de esta línea suman 20 horas y el SII dice 40.00 (cantidad_horas_nombramiento).',
    ]);
});

it('sin el paso de docentes pide correrlo antes', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

    expect(fn () => [...(new PositionsStep)->batches($migration)])->toThrow(SiiStepOutOfOrder::class, 'teacher_roster');
});
