<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Queries\StaffingBlockersQuery;

/*
| Lo que impide pasar una carga a asignación docente, calculado antes de que el jefe lo intente (tarea 3.9): cuántos
| grupos del departamento tienen choques duros y, en un campus con bloques de tiempo, cuántos no tienen horario (la
| misma cuenta que el veto de Scheduling, BlockStaffingWithHardConflicts).
*/

beforeEach(function () {
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Proposed, 'department_id' => departmentKey(10)]);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0]);
});

/**
 * Un grupo de la carga, de una carrera que se ofrece en el campus, de 2 h semanales.
 *
 * @param  array<string, mixed>  $attributes
 */
function blockersGroup(array $attributes = []): Group
{
    $group = Group::factory()->create(['term_id' => test()->load->term_id, 'campus_id' => test()->load->campus_id, 'subject_id' => test()->subject->id, 'capacity' => 30, ...$attributes]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    return $group;
}

it('cuenta los grupos con choques duros y los grupos sin horario', function () {
    $room = Room::factory()->capacity(20)->create(['building_id' => Building::factory()->create(['campus_id' => $this->load->campus_id])->id]);
    GroupSession::factory()->for(blockersGroup())->inRoom($room)->on(1, '07:00', '09:00')->create();
    blockersGroup();
    blockersGroup();
    GroupSession::factory()->for(blockersGroup())->on(2, '07:00', '09:00')->create();

    expect(app(StaffingBlockersQuery::class)->of($this->load))->toBe(['conflicted' => 1, 'unscheduled' => 2]);
});

it('no cuenta grupos cancelados ni de otros departamentos', function () {
    GroupSession::factory()->for(blockersGroup())->on(1, '07:00', '09:00')->create();
    blockersGroup(['status' => 'cancelled']);
    Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => $this->load->campus_id]);

    expect(app(StaffingBlockersQuery::class)->of($this->load))->toBe(['conflicted' => 0, 'unscheduled' => 0]);
});

it('sin bloques de tiempo no pide horario', function () {
    $this->load->campus->update(['settings' => ['uses_time_blocks' => false]]);
    blockersGroup();

    expect(app(StaffingBlockersQuery::class)->of($this->load->fresh()))->toBe(['conflicted' => 0, 'unscheduled' => 0]);
});
