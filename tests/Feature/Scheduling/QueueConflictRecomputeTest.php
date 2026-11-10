<?php

use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Facades\Queue;

/*
| QueueConflictRecompute: «el job recalcula al guardar sesión, docente o salón» (tarea 3.2). Cada evento encola el
| recálculo de las cargas (periodo y campus) que cambió.
*/

beforeEach(function () {
    Queue::fake([RecomputeScheduleConflicts::class]);
    $this->term = Term::factory()->create(['starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()]);
    $this->teacher = Teacher::factory()->create();
    $this->group = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
    $this->elsewhere = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
});

/**
 * Los [periodo, campus] encolados, ordenados.
 *
 * @return list<array{string, string}>
 */
function queuedRecomputes(): array
{
    return Queue::pushed(RecomputeScheduleConflicts::class)
        ->map(fn (RecomputeScheduleConflicts $job): array => [$job->termId, $job->campusId])
        ->sort()->values()->all();
}

function pairOf(Group $group): array
{
    return [$group->term_id, $group->campus_id];
}

it('cambiar sesiones, cancelar o cambiar de docente recalcula el campus del grupo y los del docente', function (string $event) {
    event(match ($event) {
        'sesiones' => new GroupScheduleChanged($this->group),
        'cancelar' => new GroupCancelled($this->group),
        'docente' => new GroupTeacherChanged($this->group, null, $this->teacher->id),
    });

    expect(queuedRecomputes())->toEqualCanonicalizing([pairOf($this->group), pairOf($this->elsewhere)]);
})->with(['sesiones', 'cancelar', 'docente']);

it('crear grupos recalcula su campus', function () {
    event(new GroupsCreated($this->term->id, $this->group->campus_id, [$this->group->id]));

    expect(queuedRecomputes())->toBe([pairOf($this->group)]);
});

it('cambiar un salón o sus bloqueos recalcula su campus en los periodos que siguen abiertos, una sola vez por carga', function () {
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->group->campus_id])->id]);
    Group::factory()->create(['campus_id' => $this->group->campus_id, 'term_id' => Term::factory()->create(['starts_on' => '2020-01-18', 'ends_on' => '2020-06-01'])->id]);

    event(new RoomUpdated($room, ['capacity']));
    event(new RoomBlockAdded(RoomBlock::factory()->for($room)->create(['term_id' => $this->term->id])));

    expect(queuedRecomputes())->toBe([pairOf($this->group)]);
});

it('cambiar la disponibilidad, las horas externas o las plazas de un docente recalcula sus campus, una vez por carga', function () {
    event(new AvailabilityChanged($this->teacher, $this->term));
    event(new ExternalLoadChanged(TeacherExternalLoad::factory()->for($this->teacher)->create(['term_id' => $this->term->id])));
    event(new PositionChanged(TeacherPosition::factory()->for($this->teacher)->create()));

    expect(queuedRecomputes())->toEqualCanonicalizing([pairOf($this->group), pairOf($this->elsewhere)]);
});
