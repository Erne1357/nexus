<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Queries\GroupClosingFlags;
use App\Modules\Scheduling\Queries\GroupClosingFlagsQuery;
use Tests\Support\Staffing\ClosingWorld;

/*
| GroupClosingFlagsQuery (HC-15): las banderas de cada grupo activo, en una consulta, la única definición en SQL de lo
| que el cierre revisa por grupo. La leen el veto (ClosingBlockersQuery), «Cierre de la carga» y «Avance del cierre».
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->flags = app(GroupClosingFlagsQuery::class);
});

/**
 * @return array{lacksTeacher: bool, hasSessions: bool, roomlessSessions: int, inactiveTeacher: bool, justified: bool, storedHardConflict: bool}
 */
function closingFlagsOf(GroupClosingFlags $flags): array
{
    return [
        'lacksTeacher' => $flags->lacksTeacher(),
        'hasSessions' => $flags->hasSessions,
        'roomlessSessions' => $flags->roomlessSessions,
        'inactiveTeacher' => $flags->inactiveTeacher,
        'justified' => $flags->justified,
        'storedHardConflict' => $flags->storedHardConflict,
    ];
}

it('HC-15: da las banderas de cada grupo activo de la carga, por id', function () {
    $jefe = $this->world->head();
    $complete = $this->world->group();
    $noTeacher = $this->world->group(teacher: false);
    $unscheduled = $this->world->group(scheduled: false);
    $roomless = $this->world->group(withRoom: false);
    GroupSession::factory()->for($roomless)->on(3, '07:00', '09:00')->create();
    GroupSession::factory()->for($roomless)->virtual()->on(4, '07:00', '09:00')->create();
    $inactive = $this->world->group(teacher: $this->world->teacher(active: false));
    $justified = $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $jefe);
    $blank = $this->world->justify($this->world->group(teacher: false), '   ', $jefe);
    $this->world->group(['status' => 'cancelled']);
    ScheduleConflict::factory()->create(['group_id' => $complete->id, 'term_id' => $complete->term_id, 'campus_id' => $complete->campus_id, 'department_id' => $complete->department_id]);
    ScheduleConflict::factory()->create(['group_id' => $noTeacher->id, 'term_id' => $noTeacher->term_id, 'campus_id' => $noTeacher->campus_id, 'department_id' => $noTeacher->department_id, 'resolved_at' => now()]);

    $flags = $this->flags->ofLoad($this->world->load);
    $row = fn (bool $lacksTeacher = false, bool $hasSessions = true, int $roomlessSessions = 0, bool $inactiveTeacher = false, bool $justified = false, bool $storedHardConflict = false): array => compact('lacksTeacher', 'hasSessions', 'roomlessSessions', 'inactiveTeacher', 'justified', 'storedHardConflict');

    expect($flags->map(closingFlagsOf(...))->all())->toEqual([
        $complete->id => $row(storedHardConflict: true),
        $noTeacher->id => $row(lacksTeacher: true),
        $unscheduled->id => $row(hasSessions: false),
        $roomless->id => $row(roomlessSessions: 2),
        $inactive->id => $row(inactiveTeacher: true),
        $justified->id => $row(lacksTeacher: true, justified: true),
        $blank->id => $row(lacksTeacher: true),
    ]);
});

it('con ids, solo esos grupos de la carga', function () {
    $first = $this->world->group();
    $this->world->group();

    expect($this->flags->ofLoad($this->world->load, [$first->id])->keys()->all())->toBe([$first->id]);
});

it('de todo el periodo da los grupos activos de cada campus y departamento', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $own = $this->world->group();
    $other = $this->world->group(load: $this->world->loadIn($c1));
    $this->world->group(['status' => 'cancelled']);

    $flags = $this->flags->ofTerm($this->world->term->id)->keyBy('id');

    expect($flags->map(fn (GroupClosingFlags $group): string => "{$group->campusId}|{$group->departmentId}")->sortKeys()->all())->toBe(collect([
        $own->id => "{$this->world->campus->id}|{$this->world->department->id}",
        $other->id => "{$c1->id}|{$this->world->department->id}",
    ])->sortKeys()->all());
});
