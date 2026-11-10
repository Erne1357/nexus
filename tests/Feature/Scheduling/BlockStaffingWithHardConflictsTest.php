<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/*
| La carga pasa a asignación de docentes (proposed → staffing, o adjusting → staffing) solo con grupos y sesiones sin
| choques duros (proceso-propuesto.md). Scheduling lo veta con LoadTransitioning.
*/

beforeEach(function () {
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Proposed, 'department_id' => departmentKey(10)]);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0]);
});

/**
 * Un grupo de la carga, de una carrera que se ofrece en el campus (HC-13), de 2 h semanales.
 */
function loadGroup(array $attributes = []): Group
{
    $group = Group::factory()->create(['term_id' => test()->load->term_id, 'campus_id' => test()->load->campus_id, 'subject_id' => test()->subject->id, 'capacity' => 30, ...$attributes]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    return $group;
}

function staffingVeto(LoadStatus $from = LoadStatus::Proposed, LoadStatus $to = LoadStatus::Staffing): LoadTransitioning
{
    $event = new LoadTransitioning(test()->load, $from, $to);
    event($event);

    return $event;
}

it('veta pasar a asignación con grupos sin horario', function () {
    loadGroup();

    expect(staffingVeto()->reasons())->toBe(['1 grupo no tiene horario: acomódalos en el editor antes de asignar docentes.']);
});

it('HC-15: veta pasar a asignación con choques duros, también de vuelta desde ajustes', function () {
    $room = Room::factory()->capacity(20)->create(['building_id' => Building::factory()->create(['campus_id' => $this->load->campus_id])->id]);
    GroupSession::factory()->for(loadGroup())->inRoom($room)->on(1, '07:00', '09:00')->create();

    expect(staffingVeto()->reasons()[0])->toContain('1 grupo tiene choques duros')
        ->and(staffingVeto(LoadStatus::Adjusting)->blocked())->toBeTrue();
});

it('deja pasar una carga sin choques duros y no mira otras transiciones ni otros departamentos', function () {
    GroupSession::factory()->for(loadGroup())->on(1, '07:00', '09:00')->create();
    Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => $this->load->campus_id]);

    expect(staffingVeto()->blocked())->toBeFalse()
        ->and(array_filter(staffingVeto(LoadStatus::Staffing, LoadStatus::Closed)->reasons(), fn (string $reason): bool => str_contains($reason, 'antes de asignar docentes')))->toBe([]);
});

it('sin bloques de tiempo no pide horario', function () {
    $this->load->campus->update(['settings' => ['uses_time_blocks' => false]]);
    loadGroup();

    expect(staffingVeto()->blocked())->toBeFalse();
});
