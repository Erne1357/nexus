<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\ImportLoadProjection;
use App\Modules\Scheduling\Models\Group;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Activitylog\Models\Activity;

/*
| ImportLoadProjection y `academic-load:import-projection` (tarea 1.24.8, R-PRY-1): carga la proyección de carga del
| departamento desde su xlsx en el periodo, la modalidad y el campus indicados. Días por créditos: 5 = lunes a viernes,
| 4 = lunes a jueves, 1 = viernes (confirmado); 6 = lunes a viernes con la sexta hora el lunes (supuesto por confirmar).
| El archivo se arma aquí, sin datos reales.
*/

/**
 * Un xlsx con el formato de proyecto docente: dos títulos, una fila vacía, el encabezado y las filas dadas.
 *
 * @param  list<list<mixed>>  $rows
 */
function projectionFile(array $rows): string
{
    $path = sys_get_temp_dir().'/'.uniqid('nexus-projection-', true).'.xlsx';
    register_shutdown_function(fn () => @unlink($path));
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['DIVISIÓN DE ESTUDIOS PROFESIONALES']));
    $writer->addRow(Row::fromValues(['HORARIOS PARA PERÍODO ENERO-JUNIO 2027']));
    $writer->addRow(Row::fromValues([]));
    $writer->addRow(Row::fromValues(['PLAN DE ESTUDIOS', 'SEMESTRE', 'MATERIA', 'CLAVE DE MATERIA', 'CREDITOS', 'CLAVE DEL GRUPO', 'CANTIDAD DE ALUMNOS', 'HORA', 'AULA']));

    foreach ($rows as $values) {
        $writer->addRow(Row::fromValues($values));
    }

    $writer->close();

    return $path;
}

function projectionDays(Group $group): array
{
    return $group->sessions()->orderBy('day')->get()->mapWithKeys(fn ($session): array => [$session->day => substr((string) $session->starts_at, 0, 5).'-'.substr((string) $session->ends_at, 0, 5)])->all();
}

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->department = Department::factory()->create(['code' => '110500']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224']);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->department->id, 'status' => LoadStatus::Draft]);
    $this->prog = Subject::factory()->create(['code' => 'AID-2501', 'department_id' => $this->department->id, 'credits' => 5]);
    $this->bases = Subject::factory()->create(['code' => 'CBD-2415', 'department_id' => $this->department->id, 'credits' => 4]);
    $this->poo = Subject::factory()->create(['code' => 'XYZ-9999', 'department_id' => $this->department->id, 'credits' => 1]);
    SubjectAlias::query()->create(['subject_id' => $this->poo->id, 'study_plan_id' => $this->plan->id, 'code' => '2EB', 'source' => 'sii']);
    $this->block = 'ISIC-2010-224. INGENIERÍA EN SISTEMAS COMPUTACIONALES';
    $this->import = fn (array $rows, bool $dry = false) => app(ImportLoadProjection::class)->handle(projectionFile($rows), $this->term, $this->campus, $this->pres, $dry);
});

it('carga el grupo con cupo, cuota dueña y sesiones de cinco créditos de lunes a viernes (R-PRY-1)', function () {
    $report = ($this->import)([[$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10']]);

    $group = Group::query()->sole();

    expect($report['created'])->toBe(1)
        ->and($group->code)->toBe('C')
        ->and($group->capacity)->toBe(30)
        ->and($group->semester)->toBe(1)
        ->and($group->campus_id)->toBe($this->campus->id)
        ->and($group->modality_id)->toBe($this->pres->id)
        ->and($group->department_id)->toBe($this->department->id)
        ->and($group->owner_career_id)->toBe($this->career->id)
        ->and($group->study_plan_id)->toBe($this->plan->id)
        ->and($group->teacher_id)->toBeNull()
        ->and(projectionDays($group))->toBe([1 => '09:00-10:00', 2 => '09:00-10:00', 3 => '09:00-10:00', 4 => '09:00-10:00', 5 => '09:00-10:00'])
        ->and($group->sessions()->whereNotNull('room_id')->count())->toBe(0);

    $quota = $group->quotas()->sole();
    expect($quota->is_owner)->toBeTrue()->and($quota->career_id)->toBe($this->career->id)->and($quota->max_seats)->toBe(30);
});

it('da cuatro créditos de lunes a jueves y un crédito el viernes, y resuelve la clave sin guion y por alias', function () {
    $report = ($this->import)([
        [$this->block, 1, 'BASES', 'CBD2415', 4, 'B', 25, '8 - 9'],
        ['', 2, 'TUTORÍAS', '2EB', 1, 'D', 20, '10 - 11'],
    ]);

    expect($report['created'])->toBe(2)
        ->and($report['resolved_via'])->toBe(['hyphen' => 1, 'alias' => 1])
        ->and(projectionDays(Group::query()->where('subject_id', $this->bases->id)->sole()))->toBe([1 => '08:00-09:00', 2 => '08:00-09:00', 3 => '08:00-09:00', 4 => '08:00-09:00'])
        ->and(projectionDays(Group::query()->where('subject_id', $this->poo->id)->sole()))->toBe([5 => '10:00-11:00']);
});

it('R-PRY-1: da seis créditos de lunes a viernes y deja la sexta hora por acomodar a mano (confirmado el 20 de noviembre de 2026)', function () {
    $report = ($this->import)([[$this->block, 4, 'SEIS', 'AID-2501', 6, 'E', 22, '11 - 12']]);

    expect(projectionDays(Group::query()->sole()))->toBe([1 => '11:00-12:00', 2 => '11:00-12:00', 3 => '11:00-12:00', 4 => '11:00-12:00', 5 => '11:00-12:00'])
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'falta acomodar a mano 1 hora')))->toBeTrue()
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, '6 créditos usan la regla de días que es supuesto')))->toBeFalse();
});

it('omite las filas sin hora o #N/A y reporta lo que no carga: materia, plan, bloque sin clave y créditos', function () {
    $report = ($this->import)([
        [$this->block, 1, 'OK', 'AID-2501', 5, 'C', 30, '9 - 10'],
        ['', 3, 'SIN HORA', 'AID-2501', 5, 'D', 30, '#N/A'],
        ['', 3, 'SIN GRUPO', 'CBD-2415', 5, '', '', '#N/A'],
        ['', 3, 'NO EXISTE', 'ZZZ9', 5, 'C', 20, '9 - 10'],
        ['', 3, 'RARA', 'CBD-2415', 9, 'C', 20, '9 - 10'],
        ['ITIC-2010-225 INGENIERÍA EN TECNOLOGÍAS', 1, 'OTRO PLAN', 'AID-2501', 5, 'F', 20, '12 - 13'],
        [' LICENCIATURA EN ADMINISTRACIÓN', 1, 'SIN CLAVE', 'AID-2501', 5, 'F', 20, '12 - 13'],
    ]);

    expect($report['created'])->toBe(1)
        ->and($report['skipped_by_kind'])->toBe(['omitted' => 2, 'unresolved_subject' => 1, 'bad_credits' => 1, 'unknown_plan' => 1, 'no_plan' => 1])
        ->and($report['unresolved_subjects'])->toBe(['ZZZ9' => 1])
        ->and(Group::query()->count())->toBe(1)
        ->and(Subject::query()->where('code', 'ZZZ9')->exists())->toBeFalse();
});

it('toma la hora de la letra del grupo si la fórmula del archivo no trae valor guardado', function () {
    ($this->import)([[$this->block, 1, 'FÓRMULA', 'AID-2501', 5, 'F', 30, '=IF(F5="F","12 - 13","")']]);

    expect(projectionDays(Group::query()->sole())[1])->toBe('12:00-13:00');
});

it('reporta la fila si la carga del departamento no existe o ya no se edita', function () {
    $other = Department::factory()->create(['code' => '110300']);
    $closed = Department::factory()->create(['code' => '110900']);
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $closed->id, 'status' => LoadStatus::Published]);
    Subject::factory()->create(['code' => 'AAA-1111', 'department_id' => $other->id]);
    Subject::factory()->create(['code' => 'BBB-2222', 'department_id' => $closed->id]);

    $report = ($this->import)([
        [$this->block, 1, 'SIN CARGA', 'AAA-1111', 5, 'C', 30, '9 - 10'],
        ['', 1, 'PUBLICADA', 'BBB-2222', 5, 'C', 30, '9 - 10'],
    ]);

    expect($report['created'])->toBe(0)->and($report['skipped_by_kind'])->toBe(['no_load' => 1, 'load_not_editable' => 1])->and(Group::query()->count())->toBe(0);
});

it('es idempotente: la segunda corrida no duplica y avisa', function () {
    $rows = [[$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10']];

    ($this->import)($rows);
    $second = ($this->import)($rows);

    expect($second['created'])->toBe(0)->and($second['skipped_by_kind'])->toBe(['duplicate' => 1])->and(Group::query()->count())->toBe(1);
});

it('avisa si la letra del archivo no coincide con la clave que sale de la hora', function () {
    $report = ($this->import)([
        [$this->block, 1, 'UNO', 'AID-2501', 5, 'C', 30, '9 - 10'],
        ['', 2, 'DOS', 'AID-2501', 5, 'B', 30, '9 - 10'],
    ]);

    expect($report['created'])->toBe(2)
        ->and(Group::query()->orderBy('code')->pluck('code')->all())->toBe(['C', 'C2'])
        ->and($report['warnings'])->toHaveCount(1)
        ->and($report['warnings'][0])->toContain('queda con la clave C2');
});

it('con --dry-run calcula el mismo reporte y no escribe nada', function () {
    $rows = [[$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'D', 30, '9 - 10']];

    $dry = ($this->import)($rows, true);

    expect($dry['dry_run'])->toBeTrue()->and($dry['created'])->toBe(1)->and($dry['sessions'])->toBe(5)
        ->and($dry['per_department'])->toBe(['110500' => 1])
        ->and($dry['warnings'])->toHaveCount(1)
        ->and(Group::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'load_projection_imported')->count())->toBe(0);
});

it('deja el resumen en la bitácora y el comando imprime el reporte', function () {
    $path = projectionFile([[$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10']]);

    $user = User::factory()->create();

    $this->artisan('academic-load:import-projection', ['file' => $path, '--term' => '20271', '--campus' => 'C1', '--user' => $user->username])
        ->expectsOutputToContain('Grupos creados: 1')
        ->assertSuccessful();

    expect(Activity::query()->where('event', 'load_projection_imported')->sole()->properties['created'])->toBe(1);

    $this->artisan('academic-load:import-projection', ['file' => $path, '--campus' => 'XX', '--user' => $user->username])->assertFailed();
});

it('crea los dos grupos en paralelo de la misma materia a la misma hora, y una segunda corrida no los duplica', function () {
    $rows = [
        [$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10'],
        ['', 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 28, '9 - 10'],
    ];

    $first = ($this->import)($rows);
    $second = ($this->import)($rows);
    $third = ($this->import)([...$rows, ['', 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 20, '9 - 10']]);

    expect($first['created'])->toBe(2)
        ->and(Group::query()->orderBy('code')->pluck('code')->all())->toBe(['C', 'C2', 'C3'])
        ->and($second['created'])->toBe(0)->and($second['skipped_by_kind'])->toBe(['duplicate' => 2])
        ->and($third['created'])->toBe(1);
});

it('un grupo igual en otro campus o en otro plan de la misma carrera no es duplicado', function () {
    $rows = [[$this->block, 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10']];
    ($this->import)($rows);

    $otherCampus = Campus::factory()->create(['code' => 'C2']);
    $inOtherCampus = app(ImportLoadProjection::class)->handle(projectionFile($rows), $this->term, $otherCampus, $this->pres);

    StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2020-300']);
    $inOtherPlan = ($this->import)([['ISIC-2020-300. OTRA RETÍCULA', 1, 'PROGRAMACIÓN', 'AID-2501', 5, 'C', 30, '9 - 10']]);

    expect($inOtherCampus['created'])->toBe(1)->and($inOtherPlan['created'])->toBe(1)->and(Group::query()->count())->toBe(3);
});

it('avisa de los créditos con regla supuesta (2 y 3) y no los de regla confirmada', function () {
    $report = ($this->import)([
        [$this->block, 1, 'DOS', 'AID-2501', 2, 'O', 30, '21 - 22'],
        ['', 2, 'CINCO', 'CBD-2415', 5, 'O', 30, '21 - 22'],
    ]);

    expect($report['created'])->toBe(2)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, '2 créditos usan la regla')))->toBeTrue()
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, '5 créditos usan la regla')))->toBeFalse();
});
