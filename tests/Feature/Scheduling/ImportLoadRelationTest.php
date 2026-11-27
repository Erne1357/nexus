<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\ImportLoadProjection;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ImportLoadProjection con el csv de la «Relación de materias» (1.40, R-PRY-1): grupos que faltan, sin duplicar los que
| existen, GL y #N/D sin sesiones (D3, confirmado el 25 de noviembre de 2026) y salones solo si están libres. Sin datos reales.
*/

function relationFile(array $lines): string
{
    $path = sys_get_temp_dir().'/'.uniqid('nexus-relation-import-', true).'.csv';
    register_shutdown_function(fn () => @unlink($path));
    $header = ';BASE DOCENTE;MAESTRO;CLAVE DE MATERIA;HORA;;MATERIA;SEMESTRE;CLAVE CARRERA;CARRERA;CREDITOS;SALÓN;ALUMNOS;LABORATORIO / SALÓN;';
    file_put_contents($path, implode("\r\n", [';;ENERO - JUNIO 2027;;;;;;;;;;;;', $header, ...$lines, ';;;;;;;;;;;;;;'])."\r\n");

    return $path;
}

/** Un renglón: [clave, letra, rango, semestre, créditos, salón, alumnos]. */
function relationLine(string $key, string $letter, string $range, string|int $semester, int $credits, string $room, int $students, string $plan = 'ISIC-2010-224. INGENIERÍA EN SISTEMAS'): string
{
    return ";X;;{$key};{$letter};{$range};MATERIA;{$semester};{$plan};SIS;{$credits};{$room};{$students};SALON;";
}

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->department = Department::factory()->create(['code' => '110500']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224']);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->department->id, 'status' => LoadStatus::Proposed]);
    $this->prog = Subject::factory()->create(['code' => 'AID-2501', 'department_id' => $this->department->id, 'credits' => 5]);
    $this->redes = Subject::factory()->create(['code' => 'XYZ-9999', 'department_id' => $this->department->id, 'credits' => 4]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $this->plan->id, 'subject_id' => $this->redes->id, 'semester' => 5]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => '703']);
    $this->actor = User::factory()->create();
    $this->import = fn (array $lines, bool $dry = false) => app(ImportLoadProjection::class)->handle(relationFile($lines), $this->term, $this->campus, $this->pres, $dry, $this->actor);
});

it('R-PRY-1: crea los grupos de la relación con cupo, plan, semestre de respaldo y los días por créditos', function () {
    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30),
        relationLine('XYZ-9999', 'B', '8 - 9', '', 4, '', 25),
    ]);

    $prog = Group::query()->where('subject_id', $this->prog->id)->sole();
    $redes = Group::query()->where('subject_id', $this->redes->id)->sole();

    expect($report['created'])->toBe(2)->and($report['sessions'])->toBe(9)
        ->and($prog->code)->toBe('C')->and($prog->capacity)->toBe(30)->and($prog->study_plan_id)->toBe($this->plan->id)
        ->and($prog->sessions)->toHaveCount(5)
        ->and($redes->semester)->toBe(5)
        ->and($redes->sessions)->toHaveCount(4)
        ->and($redes->quotas()->sole()->is_owner)->toBeTrue();
});

it('R-PRY-1: GL y el rango #N/D crean un grupo sin sesiones, y una segunda corrida no lo duplica (D3)', function () {
    $lines = [
        relationLine('XYZ-9999', 'GL', '#N/D', 5, 4, '', 2),
        relationLine('AID-2501', 'G', '#N/D', 1, 5, '', 20),
    ];

    $first = ($this->import)($lines);
    $second = ($this->import)($lines);

    expect($first['created'])->toBe(2)->and($first['no_schedule'])->toBe(2)
        ->and($second['created'])->toBe(0)->and($second['existing'])->toBe(2)
        ->and(Group::query()->count())->toBe(2)
        ->and(GroupSession::query()->count())->toBe(0)
        ->and(Group::query()->where('subject_id', $this->redes->id)->sole()->code)->toBe('1');
});

it('no duplica los grupos que ya existen: una segunda corrida con horario crea cero', function () {
    $lines = [relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)];

    ($this->import)($lines);
    $second = ($this->import)($lines);

    expect($second['created'])->toBe(0)->and($second['existing'])->toBe(1)->and(Group::query()->count())->toBe(1);
});

it('pone el salón libre en todas las sesiones del grupo nuevo', function () {
    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);

    expect($report['rooms_set'])->toBe(1)
        ->and(GroupSession::query()->whereNull('room_id')->count())->toBe(0)
        ->and(GroupSession::query()->where('room_id', $this->room->id)->count())->toBe(5);
});

it('no pone un salón ocupado por otro grupo y avisa; el grupo queda sin salón', function () {
    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30),
        relationLine('XYZ-9999', 'C', '9 - 10', 5, 4, '703', 25),
    ]);

    $redes = Group::query()->where('subject_id', $this->redes->id)->sole();

    expect($report['created'])->toBe(2)->and($report['rooms_set'])->toBe(1)
        ->and($redes->sessions()->whereNotNull('room_id')->count())->toBe(0)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'el salón 703 no se puso')))->toBeTrue();
});

it('avisa si el salón no existe en el campus y el grupo se crea igual', function () {
    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '999', 30)]);

    expect($report['created'])->toBe(1)->and($report['rooms_set'])->toBe(0)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'el salón 999 no existe')))->toBeTrue();
});

it('un grupo existente sin salón recibe el del archivo, y uno con salón no se toca', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30)]);
    expect(GroupSession::query()->whereNotNull('room_id')->count())->toBe(0);

    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);

    expect($report['created'])->toBe(0)->and($report['rooms_set'])->toBe(1)
        ->and(GroupSession::query()->where('room_id', $this->room->id)->count())->toBe(5);

    $other = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => '704']);
    $again = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '704', 30)]);

    expect($again['rooms_set'])->toBe(0)->and(GroupSession::query()->where('room_id', $other->id)->count())->toBe(0);
});

it('un grupo sin sesiones no recibe salón', function () {
    $report = ($this->import)([relationLine('XYZ-9999', 'GL', '#N/D', 5, 4, '703', 2)]);

    expect($report['created'])->toBe(1)->and($report['rooms_set'])->toBe(0)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'no tiene horario fijo')))->toBeTrue();
});

it('--dry-run no escribe nada y cuenta lo que haría', function () {
    $activities = Activity::query()->count();

    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30),
        relationLine('XYZ-9999', 'GL', '#N/D', 5, 4, '', 2),
    ], dry: true);

    expect($report['dry_run'])->toBeTrue()->and($report['created'])->toBe(2)->and($report['no_schedule'])->toBe(1)->and($report['rooms_requested'])->toBe(1)
        ->and(Group::query()->count())->toBe(0)
        ->and(GroupSession::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($activities);
});

it('reporta con su renglón el que no trae clave de plan o trae una materia desconocida, y carga el resto', function () {
    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30),
        relationLine('AID-2501', 'D', '10 - 11', 1, 5, '', 30, ''),
        relationLine('ZZZ-0000', 'E', '11 - 12', 1, 5, '', 30),
    ]);

    expect($report['created'])->toBe(1)
        ->and($report['skipped_by_kind'])->toBe(['no_plan' => 1, 'unresolved_subject' => 1])
        ->and(collect($report['skipped'])->pluck('row')->all())->toBe([4, 5])
        ->and(Subject::query()->where('code', 'ZZZ-0000')->exists())->toBeFalse();
});

it('deja la bitácora con los conteos y no cambia el estado de la carga', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30), relationLine('XYZ-9999', 'GL', '#N/D', 5, 4, '', 2)]);

    $log = Activity::query()->where('event', 'load_projection_imported')->sole();

    expect($log->properties['created'])->toBe(2)->and($log->properties['no_schedule'])->toBe(1)->and($log->properties['rooms_set'])->toBe(1)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed);
});

it('R-PRY-1: con la carga en borrador la importación también pone el salón (supuesto por confirmar S6)', function () {
    $this->load->update(['status' => LoadStatus::Draft]);

    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);

    expect($report['created'])->toBe(1)->and($report['rooms_set'])->toBe(1)
        ->and(GroupSession::query()->where('room_id', $this->room->id)->count())->toBe(5);
});

it('R-PRY-1: con la carga en borrador un salón ocupado sigue sin ponerse y se avisa', function () {
    $this->load->update(['status' => LoadStatus::Draft]);

    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30),
        relationLine('XYZ-9999', 'C', '9 - 10', 5, 4, '703', 25),
    ]);

    expect($report['rooms_set'])->toBe(1)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'el salón 703 no se puso')))->toBeTrue();
});

it('con la carga publicada el renglón se reporta y el grupo existente no recibe salón', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30)]);
    $this->load->update(['status' => LoadStatus::Published]);

    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);

    expect($report['rooms_set'])->toBe(0)
        ->and($report['skipped_by_kind'])->toBe(['load_not_editable' => 1])
        ->and(GroupSession::query()->whereNotNull('room_id')->count())->toBe(0);
});

it('el comando exige --user, también en --dry-run, y no escribe nada sin él', function () {
    $path = relationFile([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);

    $this->artisan('academic-load:import-projection', ['file' => $path, '--dry-run' => true])->assertFailed();
    $this->artisan('academic-load:import-projection', ['file' => $path, '--user' => 'no-existe'])->assertFailed();
    expect(Group::query()->count())->toBe(0);

    $this->artisan('academic-load:import-projection', ['file' => $path, '--user' => $this->actor->username])->assertSuccessful();
    expect(Group::query()->count())->toBe(1);
});

it('la bitácora del resumen queda a nombre del usuario', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30)]);

    expect(Activity::query()->where('event', 'load_projection_imported')->sole()->causer_id)->toBe($this->actor->id);
});

it('un semestre menor que 1 se trata como vacío y toma el de la retícula', function () {
    ($this->import)([relationLine('XYZ-9999', 'B', '8 - 9', 0, 4, '', 25)]);

    expect(Group::query()->sole()->semester)->toBe(5);
});

it('dos grupos paralelos iguales se crean los dos y una segunda corrida no los duplica', function () {
    $lines = [relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30), relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 28)];

    $first = ($this->import)($lines);
    $second = ($this->import)($lines);

    expect($first['created'])->toBe(2)->and($second['created'])->toBe(0)->and($second['existing'])->toBe(2)->and(Group::query()->count())->toBe(2);
});

it('un archivo con BOM y con espacios de no separación se lee igual', function () {
    $path = relationFile([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30)]);
    file_put_contents($path, "\xEF\xBB\xBF".str_replace('CLAVE DE MATERIA', "CLAVE\u{00A0}DE MATERIA", (string) file_get_contents($path)));

    $report = app(ImportLoadProjection::class)->handle($path, $this->term, $this->campus, $this->pres, false, $this->actor);

    expect($report['created'])->toBe(1);
});

it('--dry-run no deja cuotas ni salones y no cuenta salones a grupos que ya lo tienen o no tienen sesiones', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30)]);
    $groups = Group::query()->count();

    $report = ($this->import)([
        relationLine('AID-2501', 'C', '9 - 10', 1, 5, '703', 30),
        relationLine('XYZ-9999', 'GL', '#N/D', 5, 4, '703', 2),
        relationLine('XYZ-9999', 'B', '8 - 9', 5, 4, '703', 20),
    ], dry: true);

    expect($report['rooms_requested'])->toBe(1)->and(Group::query()->count())->toBe($groups)
        ->and(GroupQuota::query()->count())->toBe($groups)
        ->and(GroupSession::query()->whereNull('room_id')->count())->toBe(0);
});

it('si dos salones activos responden al mismo código no se adivina: aviso y sin salón', function () {
    foreach (['Ab1', 'aB1'] as $code) {
        Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => $code]);
    }

    $report = ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, 'AB1', 30)]);

    expect($report['created'])->toBe(1)->and($report['rooms_set'])->toBe(0)
        ->and(collect($report['warnings'])->contains(fn (string $w): bool => str_contains($w, 'más de un salón activo')))->toBeTrue();
});

it('un csv que no es la relación da un error claro y no cae al lector xlsx', function () {
    $path = sys_get_temp_dir().'/'.uniqid('nexus-notrelation-', true).'.csv';
    register_shutdown_function(fn () => @unlink($path));
    file_put_contents($path, "A;B\n1;2\n");

    $this->artisan('academic-load:import-projection', ['file' => $path, '--user' => $this->actor->username])
        ->expectsOutputToContain('no es la relación de materias')
        ->assertFailed();
});

it('handleMany sin importación rechaza la carga cerrada (en borrador ya se acepta, 1.62) y con importación rechaza la publicada', function () {
    ($this->import)([relationLine('AID-2501', 'C', '9 - 10', 1, 5, '', 30)]);
    $group = Group::query()->sole();
    $rooms = $group->sessions->mapWithKeys(fn ($session): array => [$session->id => $this->room])->all();
    $assign = app(AssignSessionRoom::class);

    // Desde 1.62 el salón se pone también con la carga en borrador; una carga cerrada sigue sin aceptarlo fuera de la importación.
    $this->load->update(['status' => LoadStatus::Closed]);
    expect(fn () => $assign->handleMany($group, $rooms, $this->actor, importing: false))->toThrow(ValidationException::class);

    $this->load->update(['status' => LoadStatus::Published]);
    expect(fn () => $assign->handleMany($group, $rooms, $this->actor, importing: true))->toThrow(ValidationException::class)
        ->and(GroupSession::query()->whereNotNull('room_id')->count())->toBe(0);
});
