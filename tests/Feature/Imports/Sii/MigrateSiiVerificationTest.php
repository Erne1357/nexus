<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\VerificationStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| imports:migrate-sii con el paso `verificacion` (decisión del 10 de noviembre de 2026, confirmada: cada corrida compara
| el original con Nexus y avisa si algo no cuadra), con los pasos de producción sobre una exportación sintética: S1
| junta los créditos de su retícula en el SII y en Nexus; S2 los junta en el SII, pero su materia MIS no está en el
| catálogo del SII, no pasa a Nexus y ahí le faltan créditos.
*/

/**
 * Las filas de `_incidencias.csv` del paso `verificacion`, sin el encabezado.
 *
 * @return list<list<string>>
 */
function verificationIncidentRows(string $dir): array
{
    $rows = array_map(str_getcsv(...), array_slice(file("{$dir}/_incidencias.csv", FILE_IGNORE_NEW_LINES), 1));

    return array_values(array_filter($rows, fn (array $row): bool => $row[0] === 'verificacion'));
}

beforeEach(function () {
    Storage::fake('imports');
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);
    User::factory()->admin()->create(['username' => 'migrador']);

    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    $this->curated = SiiFixtures::dir();

    File::copy(base_path('app/Modules/Imports/Sii/Curated/organigrama.csv'), $this->curated.'/organigrama.csv');
    File::put($this->curated.'/carreras.csv', "carrera,nombre,departamento,departamento_nombre,nota\nAAA,Carrera Triple A,110700,Económico Administrativas,\n");
    File::put($this->curated.'/planes.csv', "sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota\nA,1,AAA-2010-224,AAA,presencial,si,0,\nA,3,AAA-2004-100,AAA,presencial,si,0,\n");

    $areas = array_column((new SiiMigration(SiiExport::open($this->dir), $this->curated))->curated('organigrama'), 'clave_sii');
    File::put($this->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
        .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");

    SiiFixtures::write($this->dir.'/carreras.csv', SiiFile::Carreras, [
        SiiFixtures::line(SiiFile::Carreras, ['carrera' => "'A'", 'reticula' => "'1'", 'fecha_inicio' => "'15/08/2010'", 'creditos_totales' => '10']),
        SiiFixtures::line(SiiFile::Carreras, ['carrera' => "'A'", 'reticula' => "'3'", 'fecha_inicio' => "'15/08/2004'", 'creditos_totales' => '10']),
    ]);
    $student = fn (string $control, string $grid, string $campus): string => SiiFixtures::line(SiiFile::Alumnos, [
        'no_de_control' => "'{$control}'", 'carrera' => "'A'", 'reticula' => "'{$grid}'", 'estatus_alumno' => "'EGR'", 'campus' => "'{$campus}'",
        'semestre' => '9', 'nombre_alumno' => "'ANA'", 'apellido_paterno' => "'LOPEZ'", 'apellido_materno' => "'RUIZ'", 'periodo_ingreso_it' => "'20043'",
    ]);
    SiiFixtures::write($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos, [$student('S1', '1', '1'), $student('S2', '3', '2')]);

    SiiFixtures::write($this->dir.'/materias.csv', SiiFile::Materias, [
        SiiFixtures::line(SiiFile::Materias, ['materia' => "'CD1'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'CALCULO DIFERENCIAL'"]),
        SiiFixtures::line(SiiFile::Materias, ['materia' => "'QU1'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'QUIMICA'"]),
    ]);
    $placement = fn (string $grid, string $materia, string $semester): string => SiiFixtures::line(SiiFile::MateriasCarreras, [
        'carrera' => "'A'", 'reticula' => $grid, 'materia' => "'{$materia}'", 'creditos_materia' => '5', 'horas_teoricas' => '3', 'horas_practicas' => '2',
        'semestre_reticula' => $semester, 'especialidad' => "''", 'estatus_materia_carrera' => "'A'", 'renglon' => '1',
    ]);
    SiiFixtures::write($this->dir.'/materias_carreras.csv', SiiFile::MateriasCarreras, [
        $placement('1', 'CD1', '1'), $placement('1', 'QU1', '2'), $placement('3', 'CD1', '1'), $placement('3', 'MIS', '2'),
    ]);
    SiiFixtures::write($this->dir.'/materias_claves_tecnm.csv', SiiFile::MateriasClavesTecnm, [
        SiiFixtures::line(SiiFile::MateriasClavesTecnm, ['carrera' => "'A'", 'reticula' => '1', 'materia' => "'CD1'", 'clave_tecnm' => "'ACF-0901'"]),
        SiiFixtures::line(SiiFile::MateriasClavesTecnm, ['carrera' => "'A'", 'reticula' => '3', 'materia' => "'CD1'", 'clave_tecnm' => "'ACF-0901'"]),
    ]);
    SiiFixtures::write($this->dir.'/especialidades.csv', SiiFile::Especialidades, []);

    $history = fn (string $term, string $control, string $materia): string => SiiFixtures::line(SiiFile::HistoriaAlumno, [
        'periodo' => "'{$term}'", 'no_de_control' => "'{$control}'", 'materia' => "'{$materia}'", 'calificacion' => '90', 'tipo_evaluacion' => "'O1'", 'estatus_materia' => "'A'",
    ]);
    SiiFixtures::write($this->dir.'/historia_alumno.csv', SiiFile::HistoriaAlumno, [
        $history('20101', 'S1', 'CD1'), $history('20103', 'S1', 'QU1'), $history('20043', 'S2', 'CD1'), $history('20051', 'S2', 'MIS'),
    ]);

    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($this->curated);

    $this->migrate = fn (array $options = []): int => Artisan::call('imports:migrate-sii', [
        'dir' => $this->dir, '--user' => 'migrador',
        '--only' => ['archivo', 'organigrama', 'carreras', 'materias', 'alumnos', 'kardex', 'verificacion'],
        ...$options,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->curated);
});

it('es el último paso de la corrida', function () {
    expect(ImportsServiceProvider::SII_STEPS[array_key_last(ImportsServiceProvider::SII_STEPS)])->toBe(VerificationStep::class)
        ->and(app(VerificationStep::class)->name())->toBe('verificacion');
});

it('compara la corrida con Nexus, avisa de lo que no cuadra y marca la corrida «con diferencias» sin revertir nada', function () {
    expect(($this->migrate)())->toBe(0);

    $output = Artisan::output();
    $incidents = verificationIncidentRows($this->dir);
    $errors = array_values(array_filter($incidents, fn (array $row): bool => $row[4] === 'error'));

    expect(DB::table('sii_verificacion.alumnos')->orderBy('no_de_control')->get(['no_de_control', 'completo_sii', 'completo_nexus'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['no_de_control' => 'S1', 'completo_sii' => true, 'completo_nexus' => true],
        ['no_de_control' => 'S2', 'completo_sii' => true, 'completo_nexus' => false],
    ])
        ->and(array_map(fn (array $row): array => [$row[1], $row[2]], $errors))->toBe([['materias', 'MIS'], ['alumnos', 'S2']])
        ->and(DB::table('kardex_records')->count())->toBe(3)
        ->and($output)->toMatch('/sii_verificacion\s*\|\s*materias\s*\|\s*4\s*\|\s*3\s*\|\s*0\s*\|\s*1\s*\|/')
        ->toMatch('/sii_verificacion\s*\|\s*alumnos\s*\|\s*2\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|/')
        ->toContain('«no pasó: sin explicar»: 1 renglones')
        ->toContain('Estatus EGR del SII: 2 alumnos, 2 completos en el SII, 1 completos en Nexus, 0 no están en Nexus')
        ->toContain('Migración terminada con diferencias')
        ->not->toContain('S2');
});

it('sin diferencias termina como siempre', function () {
    SiiFixtures::write($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos, [SiiFixtures::line(SiiFile::Alumnos, [
        'no_de_control' => "'S1'", 'carrera' => "'A'", 'reticula' => "'1'", 'estatus_alumno' => "'EGR'", 'campus' => "'1'", 'semestre' => '9',
    ])]);

    expect(($this->migrate)())->toBe(0);

    expect(array_filter(verificationIncidentRows($this->dir), fn (array $row): bool => $row[4] === 'error'))->toBe([])
        ->and(Artisan::output())->toContain('Migración terminada. Incidencias:')->not->toContain('con diferencias');
});

it('en seco no verifica y lo dice', function () {
    expect(($this->migrate)(['--dry-run' => true]))->toBe(0);

    expect(verificationIncidentRows($this->dir))->toBe([
        ['verificacion', 'sin verificar', 'verificacion', '', 'warning', 'No se verificó la migración contra el SII: la corrida es un ensayo (--dry-run) y nada se escribió.'],
    ])
        ->and(Artisan::output())->toContain('No se verificó la migración contra el SII')
        ->and(DB::table('pg_tables')->where('schemaname', 'sii_verificacion')->exists())->toBeFalse();
});

it('sin el paso archivo en la corrida no verifica y lo dice', function () {
    expect(Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--only' => ['verificacion']]))->toBe(0);

    expect(verificationIncidentRows($this->dir))->toHaveCount(1)
        ->and(verificationIncidentRows($this->dir)[0][5])->toContain('el paso archivo no corrió en esta corrida')
        ->and(Artisan::output())->toContain('No se verificó la migración contra el SII')
        ->and(DB::table('pg_tables')->where('schemaname', 'sii_verificacion')->exists())->toBeFalse();
});
