<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| SyncScheduleConflicts: deja schedule_conflicts igual a lo que encuentra el validador en la carga de un campus
| (tarea 3.2), conservando lo vigente, agregando lo nuevo y marcando como resuelto lo que ya no aparece.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->room = Room::factory()->capacity(20)->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
});

function syncGroup(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'capacity' => 35, ...$attributes]);
}

function syncConflicts(): array
{
    return app(SyncScheduleConflicts::class)->handle(test()->term, test()->campus);
}

it('guarda los choques nuevos con su grupo, departamento y carrera dueña', function () {
    $group = syncGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(1, '07:00', '12:00')->create();

    $counts = syncConflicts();
    $capacity = ScheduleConflict::query()->where('rule', 'HC-05')->sole();

    expect($counts['opened'])->toBeGreaterThan(0)
        ->and($capacity)
        ->group_id->toBe($group->id)
        ->department_id->toBe($group->department_id)
        ->career_id->toBe($group->owner_career_id)
        ->subject_type->toBe('room')
        ->subject_id->toBe($this->room->id)
        ->and($capacity->related)->toMatchArray(['room_capacity' => 20, 'group_capacity' => 35]);
});

it('conserva lo vigente y marca resuelto lo que ya no aparece', function () {
    $group = syncGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(1, '07:00', '12:00')->create();
    syncConflicts();
    $before = ScheduleConflict::query()->where('rule', 'HC-05')->sole();

    $again = syncConflicts();

    expect($again['opened'])->toBe(0)
        ->and(ScheduleConflict::query()->where('rule', 'HC-05')->sole()->id)->toBe($before->id);

    $group->update(['capacity' => 20]);
    syncConflicts();

    expect($before->fresh()->resolved_at)->not->toBeNull()
        ->and(ScheduleConflict::query()->open()->where('rule', 'HC-05')->exists())->toBeFalse();
});

it('solo guarda el lado de su campus de un empalme de docente entre campus', function () {
    $teacher = Teacher::factory()->create();
    $here = syncGroup(['teacher_id' => $teacher->id]);
    $there = Group::factory()->create(['term_id' => $this->term->id]);
    GroupSession::factory()->for($here)->on(2, '07:00', '09:00')->create();
    GroupSession::factory()->for($there)->on(2, '08:00', '09:00')->create();
    DB::statement('set constraints group_sessions_teacher_no_overlap deferred');
    DB::table('groups')->where('id', $there->id)->update(['teacher_id' => $teacher->id]);

    syncConflicts();

    expect(ScheduleConflict::query()->where('rule', 'HC-01')->pluck('group_id')->all())->toBe([$here->id]);
});

it('avisa con ScheduleConflictsSynced y no toca otro periodo', function () {
    Event::fake([ScheduleConflictsSynced::class]);
    $other = ScheduleConflict::factory()->create();

    syncConflicts();

    expect($other->fresh()->resolved_at)->toBeNull();
    Event::assertDispatched(ScheduleConflictsSynced::class, fn (ScheduleConflictsSynced $event): bool => $event->termId === $this->term->id && $event->campusId === $this->campus->id);
});

it('el comando recalcula los campus con grupos del periodo', function () {
    $group = syncGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(1, '07:00', '12:00')->create();

    expect(Artisan::call('scheduling:recompute-conflicts', ['term' => '20271']))->toBe(0)
        ->and(Artisan::output())->toContain('C2:')
        ->and(ScheduleConflict::query()->where('rule', 'HC-05')->exists())->toBeTrue()
        ->and(Artisan::call('scheduling:recompute-conflicts', ['term' => '19991']))->toBe(1);
});
