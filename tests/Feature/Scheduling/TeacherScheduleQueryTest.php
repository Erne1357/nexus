<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\TeacherScheduleQuery;

/*
| TeacherScheduleQuery (tarea 3.5, la reusa «mi carga» de la 3.10): el horario de un docente en un periodo, en todos
| los campus, y su disponibilidad del periodo (no disponible y preferido).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->teacher = Teacher::factory()->create();
});

function teacherScheduleGroup(Campus $campus, array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => $campus->id, 'teacher_id' => test()->teacher->id, ...$attributes]);
}

it('trae las sesiones de los grupos del docente en el periodo, en todos los campus, por día y hora', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $c2->id])->id, 'code' => 'A-1']);
    $calculo = teacherScheduleGroup($c2, ['code' => 'D', 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial'])->id]);
    $quimica = teacherScheduleGroup($c1, ['code' => 'F', 'subject_id' => Subject::factory()->create(['code' => 'AEC-1026', 'name' => 'Química'])->id]);
    $late = GroupSession::factory()->for($calculo)->inRoom($room)->on(2, '10:00', '12:00')->create();
    $early = GroupSession::factory()->for($quimica)->practica()->on(2, '07:00', '09:00')->create();

    GroupSession::factory()->for(teacherScheduleGroup($c1, ['status' => 'cancelled']))->on(3, '07:00', '08:00')->create();
    GroupSession::factory()->for(teacherScheduleGroup($c1, ['term_id' => Term::factory()->create()->id]))->on(3, '07:00', '08:00')->create();
    GroupSession::factory()->for(teacherScheduleGroup($c1, ['teacher_id' => Teacher::factory()->create()->id]))->on(3, '07:00', '08:00')->create();

    $sessions = app(TeacherScheduleQuery::class)->for($this->teacher, $this->term)['sessions'];

    expect($sessions)->toBe([
        ['id' => $early->id, 'group_id' => $quimica->id, 'group_code' => 'F', 'subject_code' => 'AEC-1026', 'subject_name' => 'Química',
            'campus_id' => $c1->id, 'campus_code' => 'C1', 'campus_name' => 'Campus I', 'day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00',
            'kind' => SessionKind::Practica, 'room' => null],
        ['id' => $late->id, 'group_id' => $calculo->id, 'group_code' => 'D', 'subject_code' => 'ACF-0901', 'subject_name' => 'Cálculo Diferencial',
            'campus_id' => $c2->id, 'campus_code' => 'C2', 'campus_name' => 'Campus II', 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00',
            'kind' => SessionKind::Teoria, 'room' => ['id' => $room->id, 'code' => 'A-1']],
    ]);
});

it('trae su disponibilidad del periodo: lo no disponible y lo preferido, por día y hora', function () {
    TeacherAvailability::factory()->preferred()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->term->id, 'day' => 4, 'starts_at' => '16:00', 'ends_at' => '18:00']);
    TeacherAvailability::factory()->unavailable()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);
    TeacherAvailability::factory()->create(['teacher_id' => $this->teacher->id, 'term_id' => Term::factory()->create()->id]);
    TeacherAvailability::factory()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->term->id, 'day' => 5])->delete();

    expect(app(TeacherScheduleQuery::class)->for($this->teacher, $this->term)['availability'])->toBe([
        ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable],
        ['day' => 4, 'starts_at' => '16:00', 'ends_at' => '18:00', 'kind' => AvailabilityKind::Preferred],
    ]);
});
