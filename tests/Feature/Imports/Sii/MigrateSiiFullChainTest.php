<?php

use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| `imports:migrate-sii` con los diez pasos de producción (ImportsServiceProvider::SII_STEPS, sin sustituirlos) sobre
| una exportación sintética pequeña que toca cada paso: corre sin filas con error y repetirla no da ni altas ni
| cambios en ningún lote.
*/

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
    File::put($this->curated.'/aulas.csv', "aula,incluir,campus,edificio,nombre,tipo,capacidad,departamento,sesiones_desde_20251,nota\nA1,si,C1,ED1,,aula,30,,5,\nA2,si,C2,ED2,Aula dos,aula,25,,3,\n");

    $areas = array_column((new SiiMigration(SiiExport::open($this->dir), $this->curated))->curated('organigrama'), 'clave_sii');
    File::put($this->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
        .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");

    SiiFixtures::write($this->dir.'/carreras.csv', SiiFile::Carreras, [
        SiiFixtures::line(SiiFile::Carreras, ['carrera' => "'A'", 'reticula' => "'1'", 'fecha_inicio' => "'15/08/2010'"]),
        SiiFixtures::line(SiiFile::Carreras, ['carrera' => "'A'", 'reticula' => "'3'", 'fecha_inicio' => "'15/08/2004'"]),
    ]);
    $student = fn (string $control, string $grid, string $status, string $campus, string $intake): string => SiiFixtures::line(SiiFile::Alumnos, [
        'no_de_control' => "'{$control}'", 'carrera' => "'A'", 'reticula' => "'{$grid}'", 'estatus_alumno' => "'{$status}'", 'campus' => "'{$campus}'",
        'semestre' => '3', 'nombre_alumno' => "'ANA'", 'apellido_paterno' => "'LOPEZ'", 'apellido_materno' => "'RUIZ'", 'periodo_ingreso_it' => "'{$intake}'",
    ]);
    SiiFixtures::write($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos, [
        $student('S1', '1', 'ACT', '1', '20263'),
        $student('S2', '3', 'EGR', '2', '20043'),
    ]);

    SiiFixtures::write($this->dir.'/materias.csv', SiiFile::Materias, [
        SiiFixtures::line(SiiFile::Materias, ['materia' => "'CD1'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'CALCULO DIFERENCIAL'"]),
        SiiFixtures::line(SiiFile::Materias, ['materia' => "'ZZZ'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'MATERIA SIN PAR'"]),
    ]);
    $placement = fn (string $grid, string $materia, string $semester): string => SiiFixtures::line(SiiFile::MateriasCarreras, [
        'carrera' => "'A'", 'reticula' => $grid, 'materia' => "'{$materia}'", 'creditos_materia' => '5', 'horas_teoricas' => '3', 'horas_practicas' => '2',
        'semestre_reticula' => $semester, 'especialidad' => "''", 'estatus_materia_carrera' => "'A'", 'renglon' => '1',
    ]);
    SiiFixtures::write($this->dir.'/materias_carreras.csv', SiiFile::MateriasCarreras, [$placement('1', 'CD1', '1'), $placement('3', 'CD1', '1'), $placement('3', 'ZZZ', '0')]);
    SiiFixtures::write($this->dir.'/materias_claves_tecnm.csv', SiiFile::MateriasClavesTecnm, [
        SiiFixtures::line(SiiFile::MateriasClavesTecnm, ['carrera' => "'A'", 'reticula' => '1', 'materia' => "'CD1'", 'clave_tecnm' => "'ACF-0901'"]),
    ]);
    SiiFixtures::write($this->dir.'/especialidades.csv', SiiFile::Especialidades, []);

    SiiFixtures::write($this->dir.'/aulas.csv', SiiFile::Aulas, [
        SiiFixtures::line(SiiFile::Aulas, ['aula' => "'A1'", 'permite_cruce' => "'N'", 'estatus' => "'A'"]),
        SiiFixtures::line(SiiFile::Aulas, ['aula' => "'A2'", 'permite_cruce' => "'N'", 'estatus' => "'A'"]),
    ]);

    SiiFixtures::writeStaff($this->dir, [[
        'rfc' => 'PRUE800101AB1', 'clave_area' => '110100', 'nombramiento' => 'D', 'status_empleado' => '01',
        'nombre_empleado' => 'JUAN', 'apellidos_empleado' => 'PEREZ CRUZ', 'correo_electronico' => '', 'inicio_plantel' => '201702',
    ]], [[
        'rfc' => 'PRUE800101AB1', 'apellidop' => 'PEREZ', 'apellidom' => 'CRUZ', 'nombre_empleado' => 'JUAN', 'id_unidad_organica' => '110100', 'fecha_ingreso_institucion' => '',
        'claves_plazas' => '1403 E371700.0100001 1403 E352504.0100002', 'cantidad_horas_nombramiento' => '34',
    ]]);
    SiiFixtures::writeGroups($this->dir, [['20261', 'CD1', 'A', 'PRUE800101AB1'], ['20263', 'CD1', 'B', 'PRUE800101AB1']]);

    $history = fn (string $term, string $control, string $materia, string $grade, string $type, string $status): string => SiiFixtures::line(SiiFile::HistoriaAlumno, [
        'periodo' => "'{$term}'", 'no_de_control' => "'{$control}'", 'materia' => "'{$materia}'", 'calificacion' => $grade, 'tipo_evaluacion' => "'{$type}'", 'estatus_materia' => "'{$status}'",
    ]);
    SiiFixtures::write($this->dir.'/historia_alumno.csv', SiiFile::HistoriaAlumno, [
        $history('20253', 'S1', 'CD1', '60', 'O1', 'P'),
        $history('20043', 'S2', 'ZZZ', '0', 'E1', 'A'),
    ]);
    SiiFixtures::write($this->dir.'/seleccion_materias.csv', SiiFile::SeleccionMaterias, [
        SiiFixtures::line(SiiFile::SeleccionMaterias, ['periodo' => "'20263'", 'no_de_control' => "'S1'", 'materia' => "'CD1'", 'repeticion' => "'S'", 'status_seleccion' => "'I'", 'calificacion' => '']),
    ]);

    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($this->curated);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->curated);
});

it('corre los diez pasos de producción sin errores y repetirla no da altas ni cambios', function () {
    expect(app()->bound('sii.steps'))->toBeFalse()
        ->and(ImportsServiceProvider::SII_STEPS)->toHaveCount(10);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $first = ImportBatch::query()->get();

    expect(DB::table('sii_archivo._cargas')->pluck('archivo')->sort()->values()->all())->toBe(collect(SiiFile::cases())->map->value->sort()->values()->all());

    expect($first->pluck('template')->unique()->sort()->values()->all())->toBe([
        'careers', 'departments', 'kardex', 'org_units', 'pending_placements', 'positions', 'rooms', 'students', 'study_plans', 'subject_aliases', 'subjects', 'teacher_history', 'teachers',
    ])
        ->and($first->sum('error_rows'))->toBe(0)
        ->and(Subject::query()->where('code', 'ACF-0901')->exists())->toBeTrue()
        ->and(StudyPlanSubjectPending::query()->count())->toBe(1)
        ->and(Room::query()->count())->toBe(2)
        ->and(Teacher::query()->sole()->history()->count())->toBe(2)
        ->and(Teacher::query()->sole()->positions()->orderBy('kind')->pluck('base_hours', 'kind')->map(fn ($hours): int => (int) $hours)->all())->toBe(['asignatura' => 4, 'tres_cuartos' => 30])
        ->and(Student::query()->count())->toBe(2)
        ->and(KardexRecord::query()->orderBy('term_code')->get()->map(fn (KardexRecord $record): array => [$record->term_code, $record->result->value, $record->grade === null ? null : (int) $record->grade, $record->attempt])->all())->toBe([
            ['20043', 'aprobada', null, 1],
            ['20253', 'reprobada', 60, 1],
            ['20263', 'cursando', null, 2],
        ]);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $second = ImportBatch::query()->whereNotIn('id', $first->modelKeys())->get();

    expect($second)->toHaveCount($first->count())
        ->and($second->map(fn (ImportBatch $batch): array => [$batch->template, $batch->created_rows, $batch->updated_rows])->filter(fn (array $counts): bool => $counts[1] !== 0 || $counts[2] !== 0)->values()->all())->toBe([])
        ->and($second->sum('error_rows'))->toBe(0)
        ->and(StudyPlanSubjectPending::query()->count())->toBe(1);
});
