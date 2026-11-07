<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de Scheduling (docs/dominio/modelo-de-datos.md, "Scheduling"; tarea 3.1): grupos, sesiones, cupos
| por carrera y choques. La base es la red de seguridad de HC-01 (empalme de docente), HC-02 (empalme de salón)
| y HC-07 (cupos contra capacidad) aunque el código falle; los mensajes para el usuario los da el validador.
| Cada escritura rechazada va en DB::transaction() (un savepoint), para que la prueba siga después del error.
*/

/**
 * Un salón del campus del grupo.
 */
function roomForGroup(Group $group, array $attributes = []): Room
{
    return Room::factory()->create([
        'building_id' => Building::factory()->create(['campus_id' => $group->campus_id])->id,
        ...$attributes,
    ]);
}

describe('grupos', function () {
    it('el grupo nace planeado y conoce su periodo, campus, materia, departamento, carrera dueña y plan', function () {
        $group = Group::factory()->create()->fresh();

        expect($group->status)->toBe(GroupStatus::Planned)
            ->and($group->term)->toBeInstanceOf(Term::class)
            ->and($group->campus)->toBeInstanceOf(Campus::class)
            ->and($group->subject)->toBeInstanceOf(Subject::class)
            ->and($group->department_id)->toBe($group->subject->department_id)
            ->and($group->ownerCareer)->toBeInstanceOf(Career::class)
            ->and($group->studyPlan->career_id)->toBe($group->owner_career_id)
            ->and($group->teacher)->toBeNull()
            ->and($group->proposalItem)->toBeNull();
    });

    it('el grupo acepta sus tres estados y rechaza otro', function () {
        foreach (GroupStatus::cases() as $status) {
            Group::factory()->create(['status' => $status]);
        }

        expect(Group::query()->pluck('status')->all())->toEqualCanonicalizing(GroupStatus::cases())
            ->and(fn () => DB::transaction(fn () => DB::table('groups')->update(['status' => 'closed'])))
            ->toThrow(QueryException::class, 'groups_status_check');
    });

    it('el grupo rechaza semestre menor que 1 y capacidad de cero', function () {
        $group = Group::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['semester' => 0])))
            ->toThrow(QueryException::class, 'groups_semester_check')
            ->and(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['capacity' => 0])))
            ->toThrow(QueryException::class, 'groups_capacity_check');
    });

    it('el plan del grupo es de su carrera dueña', function () {
        $group = Group::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['study_plan_id' => StudyPlan::factory()->create()->id])))
            ->toThrow(QueryException::class, 'groups_study_plan_career_foreign');
    });

    it('la clave es única por periodo y materia, en cualquier campus; otra materia u otro periodo la repiten', function () {
        $group = Group::factory()->create(['code' => 'D']);

        Group::factory()->create(['code' => 'D', 'term_id' => $group->term_id]);
        Group::factory()->create(['code' => 'D', 'subject_id' => $group->subject_id]);

        expect(fn () => DB::transaction(fn () => Group::factory()->create(['code' => 'D', 'term_id' => $group->term_id, 'subject_id' => $group->subject_id])))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('el grupo creado desde una propuesta conoce su fila', function () {
        $proposal = CareerProposal::factory()->create();
        $item = ProposalItem::factory()->create([
            'career_proposal_id' => $proposal->id,
            'study_plan_id' => StudyPlan::factory()->create(['career_id' => $proposal->career_id])->id,
            'semester' => 4,
        ]);

        $group = Group::factory()->forProposalItem($item)->create()->fresh();

        expect($group->proposalItem->is($item))->toBeTrue()
            ->and($group->subject_id)->toBe($item->subject_id)
            ->and($group->study_plan_id)->toBe($item->study_plan_id)
            ->and($group->semester)->toBe($item->semester)
            ->and($group->owner_career_id)->toBe($item->proposal->career_id)
            ->and($group->term_id)->toBe($item->proposal->term_id)
            ->and($group->campus_id)->toBe($item->proposal->campus_id);
    });
});

describe('sesiones', function () {
    it('la sesión copia del grupo su periodo, campus, docente y si cuenta', function () {
        $teacher = Teacher::factory()->create();
        $group = Group::factory()->create(['teacher_id' => $teacher->id]);

        $session = GroupSession::factory()->for($group)->create()->fresh();

        expect($session->term_id)->toBe($group->term_id)
            ->and($session->campus_id)->toBe($group->campus_id)
            ->and($session->teacher_id)->toBe($teacher->id)
            ->and($session->is_active)->toBeTrue()
            ->and($session->kind)->toBe(SessionKind::Teoria)
            ->and($session->minutes)->toBe(120)
            ->and(GroupSession::factory()->for($group)->practica()->on(2, '07:30', '08:20')->create()->fresh())
            ->kind->toBe(SessionKind::Practica)
            ->minutes->toBe(50);
    });

    it('la copia no se puede falsear al escribir la sesión', function () {
        $group = Group::factory()->create();
        $session = GroupSession::factory()->for($group)->create();

        DB::table('group_sessions')->where('id', $session->id)->update([
            'teacher_id' => Teacher::factory()->create()->id,
            'term_id' => Term::factory()->create()->id,
            'is_active' => false,
        ]);

        expect($session->fresh())
            ->teacher_id->toBeNull()
            ->term_id->toBe($group->term_id)
            ->is_active->toBeTrue();
    });

    it('cambiar el docente o cancelar el grupo actualiza sus sesiones', function () {
        $group = Group::factory()->create();
        $session = GroupSession::factory()->for($group)->create();
        $teacher = Teacher::factory()->create();

        $group->update(['teacher_id' => $teacher->id]);
        expect($session->fresh()->teacher_id)->toBe($teacher->id);

        $group->update(['status' => GroupStatus::Cancelled]);
        expect($session->fresh()->is_active)->toBeFalse();
    });

    it('la sesión rechaza un día fuera de lunes a sábado, un fin que no es posterior al inicio y otro tipo', function () {
        $session = GroupSession::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('group_sessions')->where('id', $session->id)->update(['day' => 7])))
            ->toThrow(QueryException::class, 'group_sessions_day_check')
            ->and(fn () => DB::transaction(fn () => DB::table('group_sessions')->where('id', $session->id)->update(['ends_at' => '07:00'])))
            ->toThrow(QueryException::class, 'group_sessions_time_check')
            ->and(fn () => DB::transaction(fn () => DB::table('group_sessions')->where('id', $session->id)->update(['kind' => 'taller'])))
            ->toThrow(QueryException::class, 'group_sessions_kind_check');
    });

    it('el salón de la sesión es del campus del grupo', function () {
        $group = Group::factory()->create();

        expect(fn () => DB::transaction(fn () => GroupSession::factory()->for($group)->inRoom(Room::factory()->create())->create()))
            ->toThrow(QueryException::class, 'group_sessions_room_campus_foreign');
    });

    it('un grupo no tiene dos sesiones que se empalmen el mismo día', function () {
        $group = Group::factory()->create();
        GroupSession::factory()->for($group)->on(1, '09:00', '11:00')->create();
        GroupSession::factory()->for($group)->on(2, '09:00', '11:00')->create();
        GroupSession::factory()->for($group)->on(1, '11:00', '12:00')->create();

        expect(fn () => DB::transaction(fn () => GroupSession::factory()->for($group)->on(1, '10:00', '11:00')->create()))
            ->toThrow(QueryException::class, 'group_sessions_group_no_overlap');
    });

    it('HC-01: un docente no tiene dos sesiones que se empalmen el mismo día y periodo, aun en otro campus', function () {
        $teacher = Teacher::factory()->create();
        $first = Group::factory()->create(['teacher_id' => $teacher->id]);
        $other = Group::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $first->term_id]);
        GroupSession::factory()->for($first)->on(1, '09:00', '11:00')->create();

        expect($other->campus_id)->not->toBe($first->campus_id)
            ->and(fn () => DB::transaction(fn () => GroupSession::factory()->for($other)->on(1, '10:00', '12:00')->create()))
            ->toThrow(QueryException::class, 'group_sessions_teacher_no_overlap');
    });

    it('HC-01: horas contiguas, otro día, otro periodo o un grupo cancelado no se empalman', function () {
        $teacher = Teacher::factory()->create();
        $first = Group::factory()->create(['teacher_id' => $teacher->id]);
        GroupSession::factory()->for($first)->on(1, '09:00', '11:00')->create();

        $sameTerm = fn (array $attributes = []) => Group::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $first->term_id, ...$attributes]);

        GroupSession::factory()->for($sameTerm())->on(1, '11:00', '12:00')->create();
        GroupSession::factory()->for($sameTerm())->on(2, '09:00', '11:00')->create();
        GroupSession::factory()->for(Group::factory()->create(['teacher_id' => $teacher->id]))->on(1, '09:00', '11:00')->create();
        GroupSession::factory()->for($sameTerm(['status' => GroupStatus::Cancelled]))->on(1, '09:00', '11:00')->create();

        expect(GroupSession::query()->count())->toBe(5);
    });

    it('HC-01: asignar al grupo un docente que ya da clase a esa hora es rechazado', function () {
        $teacher = Teacher::factory()->create();
        $busy = Group::factory()->create(['teacher_id' => $teacher->id]);
        GroupSession::factory()->for($busy)->on(3, '07:00', '09:00')->create();
        $group = Group::factory()->create(['term_id' => $busy->term_id]);
        GroupSession::factory()->for($group)->on(3, '08:00', '10:00')->create();

        expect(fn () => DB::transaction(fn () => $group->update(['teacher_id' => $teacher->id])))
            ->toThrow(QueryException::class, 'group_sessions_teacher_no_overlap');
    });

    it('HC-01: reactivar un grupo cancelado que se empalma con el docente es rechazado', function () {
        $teacher = Teacher::factory()->create();
        $active = Group::factory()->create(['teacher_id' => $teacher->id]);
        GroupSession::factory()->for($active)->on(1, '09:00', '10:00')->create();
        $cancelled = Group::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $active->term_id, 'status' => GroupStatus::Cancelled]);
        GroupSession::factory()->for($cancelled)->on(1, '09:00', '10:00')->create();

        expect(fn () => DB::transaction(fn () => $cancelled->update(['status' => GroupStatus::Planned])))
            ->toThrow(QueryException::class, 'group_sessions_teacher_no_overlap');
    });

    it('HC-02: un salón no tiene dos sesiones que se empalmen el mismo día y periodo', function () {
        $first = Group::factory()->create();
        $room = roomForGroup($first);
        GroupSession::factory()->for($first)->inRoom($room)->on(4, '13:00', '15:00')->create();
        $other = Group::factory()->create(['term_id' => $first->term_id, 'campus_id' => $first->campus_id]);

        expect(fn () => DB::transaction(fn () => GroupSession::factory()->for($other)->inRoom($room)->on(4, '14:00', '16:00')->create()))
            ->toThrow(QueryException::class, 'group_sessions_room_no_overlap');
    });

    it('HC-02: horas contiguas, otro periodo o un grupo cancelado no ocupan el salón', function () {
        $first = Group::factory()->create();
        $room = roomForGroup($first);
        GroupSession::factory()->for($first)->inRoom($room)->on(4, '13:00', '15:00')->create();

        GroupSession::factory()->for(Group::factory()->create(['term_id' => $first->term_id, 'campus_id' => $first->campus_id]))
            ->inRoom($room)->on(4, '15:00', '16:00')->create();
        GroupSession::factory()->for(Group::factory()->create(['campus_id' => $first->campus_id]))
            ->inRoom($room)->on(4, '13:00', '15:00')->create();
        GroupSession::factory()->for(Group::factory()->create(['term_id' => $first->term_id, 'campus_id' => $first->campus_id, 'status' => GroupStatus::Cancelled]))
            ->inRoom($room)->on(4, '13:00', '15:00')->create();

        expect(GroupSession::query()->count())->toBe(4);
    });

    it('las exclusiones se pueden diferir para intercambiar dos sesiones dentro de una transacción', function () {
        $teacher = Teacher::factory()->create();
        $first = Group::factory()->create(['teacher_id' => $teacher->id]);
        $second = Group::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $first->term_id]);
        $a = GroupSession::factory()->for($first)->on(1, '07:00', '08:00')->create();
        $b = GroupSession::factory()->for($second)->on(1, '08:00', '09:00')->create();

        DB::transaction(function () use ($a, $b) {
            DB::statement('set constraints group_sessions_teacher_no_overlap deferred');
            $a->update(['starts_at' => '08:00', 'ends_at' => '09:00']);
            $b->update(['starts_at' => '07:00', 'ends_at' => '08:00']);
            DB::statement('set constraints group_sessions_teacher_no_overlap immediate');
        });

        expect($a->fresh()->starts_at)->toBe('08:00:00')
            ->and($b->fresh()->starts_at)->toBe('07:00:00');
    });
});

describe('cupos por carrera', function () {
    it('el cupo conoce su grupo y su carrera', function () {
        $group = Group::factory()->withQuota()->create();

        $quota = $group->quotas()->sole();

        expect($quota->is_owner)->toBeTrue()
            ->and($quota->career_id)->toBe($group->owner_career_id)
            ->and($quota->max_seats)->toBe($group->capacity)
            ->and($quota->group->is($group))->toBeTrue()
            ->and($quota->career)->toBeInstanceOf(Career::class);
    });

    it('HC-07: la suma de cupos no excede la capacidad del grupo', function () {
        $group = Group::factory()->withQuota(20)->create(['capacity' => 30]);

        GroupQuota::factory()->for($group)->create(['max_seats' => 10]);

        expect(fn () => DB::transaction(fn () => GroupQuota::factory()->for($group)->create(['max_seats' => 1])))
            ->toThrow(QueryException::class, 'HC-07');
    });

    it('HC-07: subir un cupo o bajar la capacidad por debajo de la suma es rechazado', function () {
        $group = Group::factory()->withQuota(30)->create(['capacity' => 30]);

        expect(fn () => DB::transaction(fn () => $group->quotas()->sole()->update(['max_seats' => 31])))
            ->toThrow(QueryException::class, 'HC-07')
            ->and(fn () => DB::transaction(fn () => $group->update(['capacity' => 29])))
            ->toThrow(QueryException::class, 'HC-07');

        $group->update(['capacity' => 40]);
        expect($group->fresh()->capacity)->toBe(40);
    });

    it('un cupo por carrera, un solo dueño y el dueño es la carrera dueña del grupo', function () {
        $group = Group::factory()->withQuota(10)->create(['capacity' => 40]);
        $career = Career::factory()->create();
        GroupQuota::factory()->for($group)->create(['career_id' => $career->id, 'max_seats' => 5]);

        expect(fn () => DB::transaction(fn () => GroupQuota::factory()->for($group)->create(['career_id' => $career->id, 'max_seats' => 5])))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => DB::transaction(fn () => GroupQuota::factory()->for($group)->create(['career_id' => Career::factory()->create()->id, 'max_seats' => 5, 'is_owner' => true])))
            ->toThrow(QueryException::class, 'group_quotas_one_owner');

        $orphan = Group::factory()->create();
        expect(fn () => DB::transaction(fn () => GroupQuota::factory()->for($orphan)->create(['career_id' => $career->id, 'is_owner' => true, 'max_seats' => 5])))
            ->toThrow(QueryException::class, 'carrera dueña');
    });

    it('el cupo no es negativo', function () {
        $group = Group::factory()->create();

        expect(fn () => DB::transaction(fn () => GroupQuota::factory()->for($group)->create(['max_seats' => -1])))
            ->toThrow(QueryException::class, 'group_quotas_max_seats_check');
    });
});

describe('choques', function () {
    it('el choque guarda su regla, gravedad, a quién toca y el detalle', function () {
        $group = Group::factory()->create();

        $conflict = ScheduleConflict::factory()->forGroup($group)->create([
            'rule' => 'HC-03',
            'related' => ['groups' => [$group->id]],
        ])->fresh();

        expect($conflict->severity)->toBe(ConflictSeverity::Hard)
            ->and($conflict->group->is($group))->toBeTrue()
            ->and($conflict->term_id)->toBe($group->term_id)
            ->and($conflict->campus_id)->toBe($group->campus_id)
            ->and($conflict->department_id)->toBe($group->department_id)
            ->and($conflict->career_id)->toBe($group->owner_career_id)
            ->and($conflict->subject_type)->toBe('group')
            ->and($conflict->subject_id)->toBe($group->id)
            ->and($conflict->related)->toBe(['groups' => [$group->id]])
            ->and($conflict->resolved_at)->toBeNull();
    });

    it('el choque rechaza otra gravedad, otro tipo de sujeto y una regla sin id', function () {
        $conflict = ScheduleConflict::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('schedule_conflicts')->where('id', $conflict->id)->update(['severity' => 'medium'])))
            ->toThrow(QueryException::class, 'schedule_conflicts_severity_check')
            ->and(fn () => DB::transaction(fn () => DB::table('schedule_conflicts')->where('id', $conflict->id)->update(['subject_type' => 'student'])))
            ->toThrow(QueryException::class, 'schedule_conflicts_subject_type_check')
            ->and(fn () => DB::transaction(fn () => DB::table('schedule_conflicts')->where('id', $conflict->id)->update(['rule' => 'empalme'])))
            ->toThrow(QueryException::class, 'schedule_conflicts_rule_check');
    });
});

describe('alcances del modelo', function () {
    it('los grupos activos son todos menos los cancelados', function () {
        $planned = Group::factory()->create();
        $confirmed = Group::factory()->confirmed()->withTeacher()->create();
        Group::factory()->cancelled()->create();

        expect(Group::query()->active()->pluck('id')->all())->toEqualCanonicalizing([$planned->id, $confirmed->id])
            ->and($confirmed->fresh()->teacher)->toBeInstanceOf(Teacher::class);
    });

    it('los choques vigentes son los que no se resolvieron', function () {
        $open = ScheduleConflict::factory()->soft()->create();
        ScheduleConflict::factory()->resolved()->create();

        expect(ScheduleConflict::query()->open()->sole()->is($open))->toBeTrue()
            ->and($open->fresh()->severity)->toBe(ConflictSeverity::Soft);
    });

    it('una sesión virtual no lleva salón', function () {
        $group = Group::factory()->create();

        $session = GroupSession::factory()->for($group)->inRoom(roomForGroup($group))->virtual()->create()->fresh();

        expect($session->kind)->toBe(SessionKind::Virtual)
            ->and($session->room)->toBeNull();
    });
});
