<?php

use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\TeacherHistoryStep;
use App\Modules\Imports\Sii\Steps\TeachersStep;
use Illuminate\Support\Facades\File;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `historial_docente` de imports:migrate-sii: una fila por grupo con docente migrado, con el RFC fundido, la
| materia de Nexus y el campus vacío (D2). Lo que no se emite deja avisos agregados. Datos sintéticos.
*/

/**
 * @return array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}
 */
function teacherHistoryStepRun(string $dir): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('department_for_area', fn (): array => [110100 => '110100']);
    $migration->remember('subject_for', fn (): array => ['M1' => 'ACF-0901', 'M2' => 'M2']);
    [...(new TeachersStep)->batches($migration)];

    foreach ((new TeacherHistoryStep)->batches($migration) as $batch) {
        return ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    $row = fn (string $rfc, string $status = '01', string $nombramiento = 'D'): array => [
        'rfc' => $rfc, 'clave_area' => '110100', 'nombramiento' => $nombramiento, 'status_empleado' => $status,
        'nombre_empleado' => 'JUAN', 'apellidos_empleado' => 'PEREZ',
    ];
    SiiFixtures::writeStaff($this->dir, [$row('PRUE800101OLD', '04'), $row('PRUE800101NEW'), $row('ADMI800101AA1', '01', 'A'), [...$row('NODE800101AA1'), 'clave_area' => '999999']]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('emite una fila por grupo con el RFC fundido, la materia de Nexus y sin campus', function () {
    SiiFixtures::writeGroups($this->dir, [['20251', 'M1', 'a', 'PRUE800101OLD'], ['20263', 'M2', 'B', 'prue800101new']]);

    $run = teacherHistoryStepRun($this->dir);

    expect($run['rows'])->toBe([
        ['rfc' => 'PRUE800101NEW', 'subject_code' => 'ACF-0901', 'term_code' => '20251', 'campus_code' => '', 'group_code' => 'M1-a'],
        ['rfc' => 'PRUE800101NEW', 'subject_code' => 'M2', 'term_code' => '20263', 'campus_code' => '', 'group_code' => 'M2-B'],
    ]);
});

it('no emite grupos sin RFC, de docentes no migrados ni de materias sin equivalente y los cuenta', function () {
    SiiFixtures::writeGroups($this->dir, [
        ['20263', 'M1', 'A', 'PRUE800101NEW'], ['20263', 'M1', 'B', ''], ['20263', 'M1', 'C', 'NODE800101AA1'],
        ['20263', 'SIN', 'D', 'PRUE800101NEW'], ['20253', 'SIN', 'E', 'PRUE800101NEW'], ['20263', 'M1', 'F', 'DESC800101ZZ9'],
    ]);

    $run = teacherHistoryStepRun($this->dir);
    $messages = collect($run['incidents'])->pluck('message')->implode("\n");

    expect(array_column($run['rows'], 'group_code'))->toBe(['M1-A'])
        ->and($messages)->toContain('La materia SIN del SII no tiene equivalente en Nexus: 2 grupos')
        ->and($messages)->toContain('1 grupos del SII no traen RFC')
        ->and($messages)->toContain('2 grupos son de un RFC que no se migró');
});

it('un grupo paralelo del mismo docente cuenta una vez', function () {
    SiiFixtures::writeGroups($this->dir, [
        ['20263', 'M1', 'A', 'PRUE800101NEW'],
        ['20263', 'M2', 'B', 'PRUE800101NEW', 'M1        A'],
        ['20263', 'M2', 'C', 'PRUE800101NEW', 'M1        Z'],
        ['20253', 'M2', 'D', 'PRUE800101NEW', 'M1        A'],
    ]);

    $run = teacherHistoryStepRun($this->dir);

    expect(array_column($run['rows'], 'group_code'))->toBe(['M1-A', 'M2-C', 'M2-D'])
        ->and(collect($run['incidents'])->pluck('message')->implode("\n"))->toContain('1 grupos paralelos cuentan una vez');
});

it('dos grupos con la misma letra de materias unificadas quedan distintos', function () {
    SiiFixtures::writeGroups($this->dir, [['20263', 'M1', 'A', 'PRUE800101NEW'], ['20263', 'M2', 'A', 'PRUE800101NEW']]);
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');
    $migration->remember('department_for_area', fn (): array => [110100 => '110100']);
    $migration->remember('subject_for', fn (): array => ['M1' => 'UNICA', 'M2' => 'UNICA']);
    [...(new TeachersStep)->batches($migration)];
    $rows = [];
    foreach ((new TeacherHistoryStep)->batches($migration) as $batch) {
        $rows = [...$batch->rows];
    }

    expect(array_column($rows, 'group_code'))->toBe(['M1-A', 'M2-A']);
});

it('omite el grupo cuya clave pasa de 20 caracteres y repetidos exactos los cuenta', function () {
    SiiFixtures::writeGroups($this->dir, [
        ['20263', 'M2', 'GRUPOMUYLARGO123456', 'PRUE800101NEW'],
        ['20263', 'M1', 'A', 'PRUE800101NEW'], ['20263', 'M1', 'A', 'PRUE800101NEW'],
    ]);

    $run = teacherHistoryStepRun($this->dir);
    $messages = collect($run['incidents'])->pluck('message')->implode("\n");

    expect(array_column($run['rows'], 'group_code'))->toBe(['M1-A'])
        ->and($messages)->toContain('1 grupos con clave de más de 20')->toContain('1 grupos repetidos');
});

it('sin los pasos anteriores pide correrlos antes', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

    expect(fn () => [...(new TeacherHistoryStep)->batches($migration)])->toThrow(SiiStepOutOfOrder::class, 'docentes');
});
