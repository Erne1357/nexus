<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomScheduleQuery;

/*
| RoomScheduleQuery (tarea 3.5): la vista por salón del editor. Las sesiones de los grupos activos en el salón en un
| periodo y sus bloqueos (los del periodo y los de todos los periodos).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
});

function roomScheduleGroup(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$attributes]);
}

it('trae las sesiones del salón en el periodo, de los grupos activos, por día y hora', function () {
    $group = roomScheduleGroup(['code' => 'D', 'teacher_id' => Teacher::factory()->create(['name' => 'Ana Pérez'])->id,
        'subject_id' => Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial'])->id]);
    $other = roomScheduleGroup(['code' => 'F', 'subject_id' => Subject::factory()->create(['code' => 'AEC-1026', 'name' => 'Química'])->id]);
    $late = GroupSession::factory()->for($group)->inRoom($this->room)->on(1, '09:00', '11:00')->create();
    $early = GroupSession::factory()->for($other)->practica()->inRoom($this->room)->on(1, '07:00', '09:00')->create();

    GroupSession::factory()->for(roomScheduleGroup(['status' => 'cancelled']))->inRoom($this->room)->on(2, '07:00', '08:00')->create();
    GroupSession::factory()->for(roomScheduleGroup(['term_id' => Term::factory()->create()->id]))->inRoom($this->room)->on(2, '07:00', '08:00')->create();
    GroupSession::factory()->for(roomScheduleGroup())->on(2, '07:00', '08:00')->create();

    expect(app(RoomScheduleQuery::class)->for($this->room, $this->term)['sessions'])->toBe([
        ['id' => $early->id, 'group_id' => $other->id, 'group_code' => 'F', 'subject_code' => 'AEC-1026', 'subject_name' => 'Química',
            'teacher' => null, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => SessionKind::Practica],
        ['id' => $late->id, 'group_id' => $group->id, 'group_code' => 'D', 'subject_code' => 'ACF-0901', 'subject_name' => 'Cálculo Diferencial',
            'teacher' => 'Ana Pérez', 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => SessionKind::Teoria],
    ]);
});

it('trae los bloqueos del salón del periodo y los de todos los periodos', function () {
    RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => $this->term->id, 'day' => 3, 'starts_at' => '12:00', 'ends_at' => '14:00', 'reason' => 'Junta']);
    RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => null, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'reason' => 'Mantenimiento']);
    RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => Term::factory()->create()->id]);
    RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => $this->term->id, 'day' => 5])->delete();
    RoomBlock::factory()->create(['term_id' => $this->term->id]);

    expect(app(RoomScheduleQuery::class)->for($this->room, $this->term)['blocks'])->toBe([
        ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'reason' => 'Mantenimiento', 'all_terms' => true],
        ['day' => 3, 'starts_at' => '12:00', 'ends_at' => '14:00', 'reason' => 'Junta', 'all_terms' => false],
    ]);
});
