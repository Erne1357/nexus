<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ClosingBlockersQuery;

/*
| HC-15 (confirmado el 10 de noviembre de 2026): una carga se cierra (staffing → closed) y se publica (closed o
| approved → published) solo sin choques duros, con todos sus grupos activos con horario (con bloques de tiempo), con
| docente activo y, con salones, con salón en cada sesión presencial. Un grupo sin docente o sin salón pasa con
| justificación; un docente dado de baja no se justifica (P10). Scheduling lo veta con LoadTransitioning, y
| ClosingBlockersQuery hace la misma cuenta para las pantallas.
*/

beforeEach(function () {
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Staffing, 'department_id' => departmentKey(10)]);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0]);
});

function closingTeacher(bool $active = true): Teacher
{
    $teacher = $active ? Teacher::factory()->create() : Teacher::factory()->inactive()->create();
    TeacherPosition::factory()->for($teacher)->asignatura(20)->create();

    return $teacher;
}

function closingRoom(int $capacity = 40): Room
{
    return Room::factory()->capacity($capacity)->create(['building_id' => Building::factory()->create(['campus_id' => loadCampus()->id])->id]);
}

/**
 * Un grupo de la carga, de una carrera ofrecida en el campus, de 2 h. Completo por omisión: el lunes de 7:00 a 9:00 en
 * un salón propio y con un docente propio, así varios grupos no se empalman.
 */
function closingGroup(array $attributes = [], bool $scheduled = true, ?Room $room = null, bool $withRoom = true, bool|Teacher|null $teacher = true): Group
{
    $teacherId = match (true) {
        $teacher === true => closingTeacher()->id,
        $teacher instanceof Teacher => $teacher->id,
        default => null,
    };
    $group = Group::factory()->create([
        'term_id' => test()->load->term_id,
        'campus_id' => loadCampus()->id,
        'subject_id' => test()->subject->id,
        'capacity' => 30,
        'teacher_id' => $teacherId,
        ...$attributes,
    ]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    if ($scheduled) {
        $session = GroupSession::factory()->for($group)->on(1, '07:00', '09:00');
        ($withRoom ? $session->inRoom($room ?? closingRoom()) : $session)->create();
    }

    return $group;
}

function closingVeto(LoadStatus $from = LoadStatus::Staffing, LoadStatus $to = LoadStatus::Closed): LoadTransitioning
{
    $event = new LoadTransitioning(test()->load->fresh(), $from, $to);
    event($event);

    return $event;
}

it('deja cerrar una carga completa', function () {
    closingGroup();
    closingGroup();

    expect(closingVeto()->blocked())->toBeFalse();
});

it('HC-15: veta cerrar con un grupo con choques duros, calculados en ese momento', function () {
    closingGroup(room: closingRoom(capacity: 20));

    expect(closingVeto()->reasons())->toBe(['HC-15: 1 grupo tiene choques duros: corrígelo en el editor de horarios.']);
});

it('HC-15: veta cerrar con grupos sin horario', function () {
    closingGroup(scheduled: false);
    closingGroup(scheduled: false);

    expect(closingVeto()->reasons())->toBe(['HC-15: 2 grupos no tienen horario.']);
});

it('HC-15: veta cerrar con grupos sin docente, salvo los justificados', function () {
    closingGroup(teacher: null);
    $justified = closingGroup(teacher: null);

    expect(closingVeto()->reasons())->toBe(['HC-15: 2 grupos no tienen docente ni justificación.']);

    $justified->forceFill(['close_justification' => 'Contratación en trámite'])->save();

    expect(closingVeto()->reasons())->toBe(['HC-15: 1 grupo no tiene docente ni justificación.']);
});

it('HC-15: veta cerrar con sesiones presenciales sin salón, salvo los justificados; la virtual no pide salón', function () {
    $group = closingGroup(withRoom: false);
    GroupSession::factory()->for(closingGroup(scheduled: false))->virtual()->on(2, '07:00', '09:00')->create();

    expect(closingVeto()->reasons())->toBe(['HC-15: 1 grupo tiene sesiones presenciales sin salón ni justificación.']);

    $group->forceFill(['close_justification' => 'El laboratorio se entrega en enero'])->save();

    expect(closingVeto()->blocked())->toBeFalse();
});

it('HC-15: veta cerrar con un docente dado de baja, aunque el grupo tenga justificación (P10)', function () {
    closingGroup(teacher: closingTeacher(active: false), attributes: ['close_justification' => 'Ya no da clase']);
    $deleted = closingTeacher();
    closingGroup(teacher: $deleted);
    $deleted->delete();

    expect(closingVeto()->reasons())->toBe(['HC-15: 2 grupos tienen un docente dado de baja: asígnales otro.']);
});

it('M1: cuenta los grupos de la carga en cualquier campus de su modalidad', function () {
    Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => Campus::factory()->create(['code' => 'C9'])->id, 'subject_id' => $this->subject->id]);

    expect(closingVeto()->blocked())->toBeTrue();
});

it('publicar vuelve a revisar HC-15 desde cerrada y desde aprobada', function (LoadStatus $from) {
    closingGroup(teacher: null);

    expect(closingVeto($from, LoadStatus::Published)->reasons())->toBe(['HC-15: 1 grupo no tiene docente ni justificación.']);
})->with([LoadStatus::Closed, LoadStatus::Approved]);

it('no mira otras transiciones, grupos cancelados ni grupos de otro departamento o modalidad', function () {
    closingGroup(teacher: null, attributes: ['status' => 'cancelled']);
    closingGroup(teacher: null, attributes: ['subject_id' => Subject::factory()->create(['department_id' => departmentKey(11)])->id]);
    Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => Campus::factory()->create()->id, 'modality_id' => modalityOf('EAD')->id, 'subject_id' => $this->subject->id]);

    expect(closingVeto()->blocked())->toBeFalse();

    closingGroup(teacher: null);

    expect(closingVeto(LoadStatus::Staffing, LoadStatus::Adjusting)->blocked())->toBeFalse()
        ->and(closingVeto(LoadStatus::Closed, LoadStatus::Adjusting)->blocked())->toBeFalse()
        ->and(closingVeto(LoadStatus::Proposed, LoadStatus::Staffing)->blocked())->toBeFalse();
});

it('sigue el perfil de la modalidad de la carga: sin salones no pide salón y sin bloques de tiempo no pide horario', function () {
    $this->load->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false, 'uses_time_blocks' => false]]);
    closingGroup(withRoom: false);
    closingGroup(scheduled: false);

    expect(closingVeto()->blocked())->toBeFalse();
});

it('ClosingBlockersQuery cuenta lo mismo que el veto y los grupos justificados', function () {
    closingGroup(room: closingRoom(capacity: 20));
    closingGroup(scheduled: false);
    closingGroup(teacher: null);
    closingGroup(teacher: null, attributes: ['close_justification' => 'Contratación en trámite']);
    closingGroup(withRoom: false);
    closingGroup(teacher: closingTeacher(active: false));

    $blockers = app(ClosingBlockersQuery::class)->of($this->load);

    expect($blockers->conflicted)->toBe(1)
        ->and($blockers->unscheduled)->toBe(1)
        ->and($blockers->withoutTeacher)->toBe(1)
        ->and($blockers->withoutRoom)->toBe(1)
        ->and($blockers->inactiveTeacher)->toBe(1)
        ->and($blockers->justified)->toBe(1)
        ->and($blockers->isClear())->toBeFalse()
        ->and($blockers->reasons())->toBe(closingVeto()->reasons());
});
