<?php

use App\Modules\Core\Actions\UpdateModality;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
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
| recálculo de las cargas (periodo y modalidad, M1) que cambió.
*/

beforeEach(function () {
    Queue::fake([RecomputeScheduleConflicts::class]);
    $this->term = Term::factory()->create(['starts_on' => today()->subMonth()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()]);
    $this->teacher = Teacher::factory()->create();
    $this->group = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
    $this->elsewhere = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id, 'modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);
});

/**
 * Los [periodo, modalidad] encolados, ordenados.
 *
 * @return list<array{string, string}>
 */
function queuedRecomputes(): array
{
    return Queue::pushed(RecomputeScheduleConflicts::class)
        ->map(fn (RecomputeScheduleConflicts $job): array => [$job->termId, $job->modalityId])
        ->sort()->values()->all();
}

function pairOf(Group $group): array
{
    return [$group->term_id, $group->modality_id];
}

it('cambiar sesiones, cancelar o cambiar de docente recalcula la modalidad del grupo y las del docente', function (string $event) {
    event(match ($event) {
        'sesiones' => new GroupScheduleChanged($this->group),
        'cancelar' => new GroupCancelled($this->group),
        'docente' => new GroupTeacherChanged($this->group, null, $this->teacher->id),
    });

    expect(queuedRecomputes())->toEqualCanonicalizing([pairOf($this->group), pairOf($this->elsewhere)]);
})->with(['sesiones', 'cancelar', 'docente']);

it('crear grupos recalcula su modalidad', function () {
    event(new GroupsCreated($this->term->id, $this->group->modality_id, [$this->group->id]));

    expect(queuedRecomputes())->toBe([pairOf($this->group)]);
});

it('los grupos de C1 y C2 de una modalidad se recalculan juntos, una sola vez', function () {
    $c1 = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);

    event(new GroupScheduleChanged($c1));

    expect($c1->campus_id)->not->toBe($this->group->campus_id)
        ->and(queuedRecomputes())->toEqualCanonicalizing([pairOf($this->group), pairOf($this->elsewhere)]);
});

it('el recálculo es único por periodo y modalidad', function () {
    expect((new RecomputeScheduleConflicts($this->term->id, $this->group->modality_id))->uniqueId())->toBe("{$this->term->id}:{$this->group->modality_id}");
});

it('cambiar un salón o sus bloqueos recalcula las modalidades con grupos en su campus en los periodos que siguen abiertos, una sola vez por carga', function () {
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->group->campus_id])->id]);
    Group::factory()->create(['campus_id' => $this->group->campus_id, 'term_id' => Term::factory()->create(['starts_on' => '2020-01-18', 'ends_on' => '2020-06-01'])->id]);

    event(new RoomUpdated($room, ['capacity']));
    event(new RoomBlockAdded(RoomBlock::factory()->for($room)->create(['term_id' => $this->term->id])));

    expect(queuedRecomputes())->toBe([pairOf($this->group)]);
});

it('cambiar la disponibilidad, las horas externas o las plazas de un docente recalcula sus modalidades, una vez por carga', function () {
    event(new AvailabilityChanged($this->teacher, $this->term));
    event(new ExternalLoadChanged(TeacherExternalLoad::factory()->for($this->teacher)->create(['term_id' => $this->term->id])));
    event(new PositionChanged(TeacherPosition::factory()->for($this->teacher)->create()));

    expect(queuedRecomputes())->toEqualCanonicalizing([pairOf($this->group), pairOf($this->elsewhere)]);
});

it('cambiar el perfil de una modalidad recalcula sus cargas en los periodos abiertos en ella que no han terminado (HC-04, HC-12)', function () {
    $presencial = Modality::query()->findOrFail($this->group->modality_id);
    $admin = User::factory()->admin()->create();
    $next = Term::factory()->create(['starts_on' => today()->addMonths(2)->toDateString(), 'ends_on' => today()->addMonths(6)->toDateString()]);
    $ended = Term::factory()->create(['starts_on' => '2020-01-18', 'ends_on' => '2020-06-01']);
    $notOpened = Term::factory()->create(['starts_on' => today()->toDateString(), 'ends_on' => today()->addMonths(4)->toDateString()]);

    foreach ([$this->term, $next, $ended] as $term) {
        TermOpening::factory()->for($term)->for($presencial)->create(['opened_by' => $admin->id]);
    }

    TermOpening::factory()->for($notOpened)->for(Modality::query()->findOrFail($this->elsewhere->modality_id))->create(['opened_by' => $admin->id]);
    $this->actingAs($admin);

    app(UpdateModality::class)->handle($presencial, ['name' => 'Presencial escolarizada']);

    expect(queuedRecomputes())->toBe([]);

    app(UpdateModality::class)->handle($presencial, ['settings' => [...ModalityProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);

    expect(queuedRecomputes())->toEqualCanonicalizing([[$this->term->id, $presencial->id], [$next->id, $presencial->id]]);
});

it('el tiempo límite del recálculo deja margen sobre el más grande y vence antes de que Redis lo entregue otra vez', function () {
    // El recálculo más grande del masivo (Presencial de Ene-Jun 2027 a punto de re-análisis: 3 611 grupos de C1 y C2,
    // 9 744 sesiones) tardó 1.5 s como SyncScheduleConflicts, sin otros corriendo (13 de noviembre de 2026).
    $largestSeconds = 1.5;
    $job = new RecomputeScheduleConflicts($this->term->id, $this->group->modality_id);
    $overlap = $job->middleware()[0];

    expect($job->timeout)->toBeGreaterThanOrEqual((int) ceil(100 * $largestSeconds))
        ->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($overlap->expiresAfter)->toBe($job->timeout);
});
