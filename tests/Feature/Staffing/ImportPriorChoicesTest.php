<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\ImportLoadProjection;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\ImportPriorChoices;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\PriorChoice;
use Spatie\Activitylog\Models\Activity;

/*
| staffing:import-prior-choices (tarea 1.41, SEL-16): lee la «Relación de materias» de 1.40, asigna a cada maestro con grupos
| escritos sus grupos y deja su elección previa; llena el tipo de espacio vacío desde la columna del archivo (decisión del 25 de noviembre de 2026).
| Idempotente y con --dry-run. Nombres y claves ficticios.
*/

function priorRelation(array $lines): string
{
    $path = sys_get_temp_dir().'/'.uniqid('nexus-prior-choices-', true).'.csv';
    register_shutdown_function(fn () => @unlink($path));
    $header = ';BASE DOCENTE;MAESTRO;CLAVE DE MATERIA;HORA;;MATERIA;SEMESTRE;CLAVE CARRERA;CARRERA;CREDITOS;SALÓN;ALUMNOS;LABORATORIO / SALÓN;';
    file_put_contents($path, implode("\r\n", [';;ENERO - JUNIO 2027;;;;;;;;;;;;', $header, ...$lines, ';;;;;;;;;;;;;;'])."\r\n");

    return $path;
}

/** Un renglón: maestro, clave, letra, rango, espacio. */
function priorLine(string $teacher, string $key, string $letter, string $range, string $space = 'SALON', int $credits = 5): string
{
    return ";X;{$teacher};{$key};{$letter};{$range};MATERIA;1;ISIC-2010-224. INGENIERÍA EN SISTEMAS;SIS;{$credits};;30;{$space};";
}

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->department = Department::factory()->create(['code' => '110500']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224']);
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->department->id, 'status' => LoadStatus::Draft]);
    $this->prog = Subject::factory()->create(['code' => 'AID-2501', 'department_id' => $this->department->id, 'credits' => 5, 'required_space' => null]);
    $this->redes = Subject::factory()->create(['code' => 'XYZ-9999', 'department_id' => $this->department->id, 'credits' => 4, 'required_space' => RequiredSpace::Laboratorio]);
    $this->luna = Teacher::factory()->adscribedTo($this->department)->create(['name' => 'Luna Ana']);
    $this->actor = User::factory()->admin()->create();
    $this->run = function (array $lines, bool $dry = false): array {
        $path = priorRelation($lines);
        app(ImportLoadProjection::class)->handle($path, $this->term, $this->campus, $this->pres, false, $this->actor);

        return app(ImportPriorChoices::class)->handle($path, $this->term, $this->campus, $this->pres, $this->actor, $dry);
    };
});

it('SEL-16: asigna sus grupos al maestro, deja su elección previa y llena el tipo de espacio vacío', function () {
    $report = ($this->run)([
        priorLine('LUNA ANA', 'AID-2501', 'C', '9 - 10', 'LABORATORIO'),
        priorLine('', 'XYZ-9999', 'D', '10 - 11', 'SALON', 4),
    ]);

    $group = Group::query()->where('subject_id', $this->prog->id)->sole();

    expect($report)->toMatchArray(['rows_with_teacher' => 1, 'teachers' => 1, 'recorded' => 1, 'groups_assigned' => 1, 'spaces_set' => 1, 'errors' => []])
        ->and($group->teacher_id)->toBe($this->luna->id)
        ->and(PriorChoice::query()->sole()->teacher_id)->toBe($this->luna->id)
        ->and($this->prog->fresh()->required_space)->toBe(RequiredSpace::SalaComputo)
        // Decisión del 25 de noviembre de 2026: la materia que ya tenía tipo de espacio no se pisa.
        ->and($this->redes->fresh()->required_space)->toBe(RequiredSpace::Laboratorio);
});

it('es idempotente: repetir no duplica asignaciones, marcas ni bitácora', function () {
    $lines = [priorLine('LUNA ANA', 'AID-2501', 'C', '9 - 10')];
    ($this->run)($lines);
    $assignments = GroupTeacherAssignment::query()->count();
    $logs = Activity::query()->count();

    $report = ($this->run)($lines);

    expect($report['groups_assigned'])->toBe(0)
        ->and($report['errors'])->toBe([])
        ->and(GroupTeacherAssignment::query()->count())->toBe($assignments)
        ->and(PriorChoice::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'prior_choice_recorded')->count())->toBe(1)
        ->and(Activity::query()->count())->toBeLessThanOrEqual($logs + 1);
});

it('--dry-run reporta lo que haría sin escribir nada', function () {
    $report = ($this->run)([priorLine('LUNA ANA', 'AID-2501', 'C', '9 - 10')], dry: true);

    expect($report)->toMatchArray(['dry_run' => true, 'recorded' => 1, 'groups_assigned' => 1, 'spaces_set' => 1])
        ->and(Group::query()->where('subject_id', $this->prog->id)->sole()->teacher_id)->toBeNull()
        ->and(PriorChoice::query()->count())->toBe(0)
        ->and($this->prog->fresh()->required_space)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('un docente ambiguo, uno sin coincidencia y un grupo que no existe son error del renglón y no escriben nada de ese docente', function () {
    Teacher::factory()->adscribedTo($this->department)->count(2)->sequence(['name' => 'Gomez Beto'], ['name' => 'GÓMEZ BETO'])->create();

    $report = ($this->run)([
        priorLine('GOMEZ BETO', 'AID-2501', 'C', '9 - 10'),
        priorLine('NADIE AQUI', 'AID-2501', 'D', '10 - 11'),
        priorLine('LUNA ANA', 'XYZ-9999', 'Z', '12 - 13', 'SALON', 4),
        priorLine('LUNA ANA', 'XYZ-9999', 'D', '10 - 11', 'SALON', 4),
    ]);

    $messages = collect($report['errors'])->pluck('message', 'row');

    expect($report['recorded'])->toBe(0)
        ->and($messages[3])->toContain('más de un docente')
        ->and($messages[4])->toContain('NADIE AQUI')
        ->and($messages[5])->toContain('No hay un grupo Z')
        ->and($messages->has(6))->toBeFalse()
        ->and(PriorChoice::query()->count())->toBe(0)
        ->and(Group::query()->whereNotNull('teacher_id')->count())->toBe(0);
});

it('HC-01: dos grupos del mismo maestro a la misma hora se reportan, también en --dry-run', function () {
    $otra = Subject::factory()->create(['code' => 'CBD-2415', 'department_id' => $this->department->id, 'credits' => 5, 'required_space' => RequiredSpace::Aula]);
    $lines = [
        priorLine('LUNA ANA', 'AID-2501', 'C', '9 - 10'),
        priorLine('LUNA ANA', 'CBD-2415', 'C', '9 - 10'),
    ];

    foreach ([true, false] as $dry) {
        $report = ($this->run)($lines, dry: $dry);

        expect($report['recorded'])->toBe(0)
            ->and(collect($report['errors'])->pluck('message')->join(' '))->toContain('HC-01')
            ->and(Group::query()->whereNotNull('teacher_id')->count())->toBe(0)
            ->and(PriorChoice::query()->count())->toBe(0);
    }
});

it('D3: el grupo sin horario fijo (GL) se asigna al maestro del renglón', function () {
    $report = ($this->run)([priorLine('LUNA ANA', 'XYZ-9999', 'GL', '#N/D', 'LABORATORIO', 1)]);

    $group = Group::query()->where('subject_id', $this->redes->id)->sole();

    expect($report['errors'])->toBe([])
        ->and($group->sessions()->count())->toBe(0)
        ->and($group->teacher_id)->toBe($this->luna->id);
});

it('Decisión del 25 de noviembre de 2026: si dos renglones de la misma materia piden tipos distintos gana el primero y se avisa', function () {
    $report = ($this->run)([
        priorLine('', 'AID-2501', 'C', '9 - 10', 'SALON'),
        priorLine('', 'AID-2501', 'D', '10 - 11', 'LABORATORIO'),
    ]);

    expect($this->prog->fresh()->required_space)->toBe(RequiredSpace::Aula)
        ->and($report['warnings'])->toHaveCount(1)
        ->and($report['warnings'][0])->toContain('gana el primero');
});

it('el comando reporta y falla si hay renglones con error', function () {
    $path = priorRelation([priorLine('NADIE AQUI', 'AID-2501', 'C', '9 - 10')]);
    app(ImportLoadProjection::class)->handle($path, $this->term, $this->campus, $this->pres, false, $this->actor);

    $this->artisan('staffing:import-prior-choices', ['file' => $path, '--dry-run' => true, '--user' => $this->actor->username])
        ->expectsOutputToContain('Simulacro')
        ->expectsOutputToContain('NADIE AQUI')
        ->assertFailed();

    $this->artisan('staffing:import-prior-choices', ['file' => '/no/existe.csv', '--user' => $this->actor->username])->assertFailed();

    // --user es obligatorio, también en --dry-run.
    $this->artisan('staffing:import-prior-choices', ['file' => $path, '--dry-run' => true])->expectsOutputToContain('--user')->assertFailed();
});

it('decisión del 25 de noviembre de 2026: SALA DE COMPUTO y AULA y LABORATORIO DE REDES se mapean a su tipo de espacio', function () {
    $aula = Subject::factory()->create(['code' => 'CBD-2415', 'department_id' => $this->department->id, 'credits' => 5, 'required_space' => null]);
    $redes = Subject::factory()->create(['code' => 'CBD-2416', 'department_id' => $this->department->id, 'credits' => 5, 'required_space' => null]);
    $this->redes->update(['required_space' => null]);

    ($this->run)([
        priorLine('', 'AID-2501', 'C', '9 - 10', 'SALA DE COMPUTO'),
        priorLine('', 'CBD-2415', 'D', '10 - 11', 'AULA'),
        priorLine('', 'CBD-2416', 'E', '11 - 12', 'LABORATORIO DE REDES'),
        priorLine('', 'XYZ-9999', 'F', '12 - 13', 'LABORATORIO', 4),
    ]);

    expect($this->prog->fresh()->required_space)->toBe(RequiredSpace::SalaComputo)
        ->and($aula->fresh()->required_space)->toBe(RequiredSpace::Aula)
        ->and($redes->fresh()->required_space)->toBe(RequiredSpace::Laboratorio)
        ->and($this->redes->fresh()->required_space)->toBe(RequiredSpace::SalaComputo);
});
