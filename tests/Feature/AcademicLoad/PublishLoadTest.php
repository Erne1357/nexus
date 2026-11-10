<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Publicar una carga (tarea 4.3, etapa 8 de proceso-propuesto.md): de `closed` (o `approved` con visto bueno) a
| `published`, con su foto versionada (HC-18) y el historial docente al día, todo o nada.
*/

beforeEach(function () {
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Closed, 'department_id' => departmentKey(10)]);
    $this->publisher = User::factory()->create(['name' => 'Laura Méndez']);
    $room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => $this->load->campus_id])->id, 'department_id' => departmentKey(10)]);
    $this->teacher = publishTeacher();

    $this->taught = publishGroup(['code' => 'A', 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($this->taught)->inRoom($room)->on(1, '07:00', '09:00')->create();
    $this->untaught = publishGroup(['code' => 'B', 'close_justification' => 'Docente en contratación.']);
    GroupSession::factory()->for($this->untaught)->inRoom($room)->on(2, '07:00', '09:00')->create();
    publishGroup(['code' => 'C', 'status' => 'cancelled']);
});

/**
 * Un grupo de 2 h sin choques duros: su carrera se ofrece en el campus (HC-13) y cabe en el salón (HC-05).
 */
function publishGroup(array $attributes): Group
{
    $group = Group::factory()->withQuota()->create([
        'term_id' => test()->load->term_id,
        'campus_id' => test()->load->campus_id,
        'subject_id' => Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0])->id,
        'capacity' => 30,
        ...$attributes,
    ]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    return $group;
}

/**
 * Un docente con horas base para sus grupos (HC-08).
 */
function publishTeacher(): Teacher
{
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);

    return $teacher;
}

function publish(?AcademicLoad $load = null): LoadSnapshot
{
    return app(PublishLoad::class)->handle($load ?? test()->load, test()->publisher, 'Carga lista');
}

/**
 * Lleva la carga de nuevo a `closed` sin pasar por la reapertura (4.1, otro riel): solo para probar que republicar
 * crea otra versión.
 */
function backToClosed(): void
{
    AcademicLoad::query()->whereKey(test()->load->id)->update(['status' => LoadStatus::Closed->value]);
}

it('publica una carga cerrada: la versión 1 con la foto de sus grupos no cancelados', function () {
    $snapshot = publish();
    $load = $this->load->fresh();

    expect($load->status)->toBe(LoadStatus::Published)
        ->and($load->current_version)->toBe(1)
        ->and($load->published_by)->toBe($this->publisher->id)
        ->and($load->published_at->equalTo($snapshot->created_at))->toBeTrue()
        ->and($snapshot->version)->toBe(1)
        ->and($snapshot->created_by)->toBe($this->publisher->id)
        ->and(SnapshotPayload::errors($snapshot->payload))->toBe([])
        ->and($snapshot->payload['version'])->toBe(1)
        ->and($snapshot->payload['published_by'])->toBe(['id' => $this->publisher->id, 'name' => 'Laura Méndez'])
        ->and(collect($snapshot->payload['groups'])->pluck('code')->sort()->values()->all())->toBe(['A', 'B'])
        ->and(collect($snapshot->payload['groups'])->pluck('justification', 'code')->all())->toEqual(['A' => null, 'B' => 'Docente en contratación.'])
        ->and($load->transitions()->sole()->only(['from_status', 'to_status', 'comment']))->toBe(['from_status' => LoadStatus::Closed, 'to_status' => LoadStatus::Published, 'comment' => 'Carga lista']);
});

it('deja una entrada de bitácora de la publicación con su versión', function () {
    publish();

    $entry = Activity::query()->where('subject_id', $this->load->id)->where('event', 'published')->sole();

    expect($entry->properties->all())->toBe(['version' => 1, 'groups' => 2, 'sessions' => 2])
        ->and($entry->causer_id)->toBe($this->publisher->id);
});

it('lleva al historial docente cada grupo con docente, con fuente nexus', function () {
    publish();

    expect(TeacherSubjectHistory::query()->get(['teacher_id', 'subject_id', 'term_code', 'campus_id', 'group_code', 'source'])->toArray())->toBe([[
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->taught->subject_id,
        'term_code' => $this->load->term->code,
        'campus_id' => $this->load->campus_id,
        'group_code' => 'A',
        'source' => HistorySource::Nexus->value,
    ]]);
});

it('HC-18: republicar crea la versión 2, conserva la 1 y lleva el historial a la nueva', function () {
    $first = publish();
    backToClosed();
    $replacement = publishTeacher();
    $this->taught->update(['teacher_id' => $replacement->id]);

    $second = publish();

    expect($second->version)->toBe(2)
        ->and($this->load->fresh()->current_version)->toBe(2)
        ->and($first->fresh()->payload)->toEqual($first->payload)
        ->and(collect($first->payload['groups'])->firstWhere('code', 'A')['teacher']['id'])->toBe($this->teacher->id)
        ->and(collect($second->payload['groups'])->firstWhere('code', 'A')['teacher']['id'])->toBe($replacement->id)
        ->and(TeacherSubjectHistory::query()->pluck('teacher_id')->all())->toBe([$replacement->id])
        ->and(TeacherSubjectHistory::onlyTrashed()->pluck('teacher_id')->all())->toBe([$this->teacher->id]);
});

it('con un veto no publica, no guarda foto ni toca el historial', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->to === LoadStatus::Published ? $event->block('2 grupos tienen choques duros (HC-15).') : null);

    expect(fn () => publish())->toThrow(ValidationException::class, '2 grupos tienen choques duros (HC-15).');

    expect($this->load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and($this->load->fresh()->current_version)->toBe(0)
        ->and(LoadSnapshot::query()->count())->toBe(0)
        ->and(TeacherSubjectHistory::query()->count())->toBe(0);
});

it('una foto que no cumple su forma no se guarda y la carga sigue cerrada', function () {
    Event::forget(LoadSnapshotRequested::class);
    Event::listen(LoadSnapshotRequested::class, fn (LoadSnapshotRequested $event) => $event->provideGroups([['code' => 'A']]));

    expect(fn () => publish())->toThrow(InvalidArgumentException::class, 'El snapshot no cumple su forma');

    expect($this->load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and(LoadSnapshot::query()->count())->toBe(0)
        ->and($this->load->transitions()->count())->toBe(0);
});

it('no publica una carga que no está cerrada ni aprobada', function () {
    $this->load->update(['status' => LoadStatus::Staffing]);

    expect(fn () => publish())->toThrow(ValidationException::class, 'no puede pasar a Publicada');

    expect(LoadSnapshot::query()->count())->toBe(0);
});

it('con visto bueno, publica la carga aprobada pero no la cerrada', function () {
    $this->load->campus->update(['settings' => ['requires_subdireccion_approval' => true]]);

    expect(fn () => publish($this->load->fresh()))->toThrow(ValidationException::class);

    $this->load->update(['status' => LoadStatus::Approved]);

    expect(publish($this->load->fresh())->version)->toBe(1);
});
