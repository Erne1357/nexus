<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ReplaceGroupSessions (tarea 6.10, SV-05): escribe de una vez el horario de varios grupos, como lo haría el editor pero
| en una sola transacción. Escribe solo lo diferente, une las sesiones que se pegan, exige las reglas del editor y deja
| que dos grupos intercambien horas: las exclusiones de la base (HC-01, HC-02) se revisan al final y, si una se rompe,
| no escribe nada.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create([
        'term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->department->id,
    ]);
    $this->teacher = Teacher::factory()->create();
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => 'LC-1']);
    $this->a = replaceGroup('A');
    $this->b = replaceGroup('B');
});

function replaceGroup(string $code): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => test()->department->id])->id,
        'teacher_id' => test()->teacher->id,
        'code' => $code,
    ]);
}

/**
 * @param  array<string, list<SessionCandidate>>  $schedules
 * @return array{groups: array<string, array{removed: int, added: int}>, loans_requested: bool, loans_blocked: bool}
 */
function replaceSessions(array $schedules): array
{
    return app(ReplaceGroupSessions::class)->handle($schedules, test()->user, 'de la corrida 1 del motor', ['solver_run_id' => 'r1']);
}

/**
 * @return list<string>
 */
function replacedSessionsOf(Group $group): array
{
    return $group->sessions()->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => "{$session->day} ".substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5).($session->room_id ? ' '.Room::find($session->room_id)->code : ''))
        ->all();
}

it('escribe solo lo diferente: conserva la sesión igual, quita la que no va y crea la nueva, con bitácora y evento', function () {
    Event::fake([GroupScheduleChanged::class]);
    $kept = GroupSession::factory()->for($this->a)->inRoom($this->room)->on(1, '07:00', '08:00')->create();
    GroupSession::factory()->for($this->a)->on(3, '07:00', '09:00')->create();

    $result = replaceSessions([$this->a->id => [
        new SessionCandidate(1, '07:00', '08:00', $this->room->id),
        new SessionCandidate(2, '09:00', '10:00', $this->room->id),
    ]]);

    expect($result['groups'])->toBe([$this->a->id => ['removed' => 1, 'added' => 1]])
        ->and(replacedSessionsOf($this->a))->toBe(['1 07:00-08:00 LC-1', '2 09:00-10:00 LC-1'])
        ->and(GroupSession::query()->whereKey($kept->id)->exists())->toBeTrue();

    $activity = Activity::query()->where('subject_id', $this->a->id)->where('event', ReplaceGroupSessions::EVENT)->sole();

    expect($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['solver_run_id'])->toBe('r1')
        ->and($activity->description)->toContain('de la corrida 1 del motor')
        ->and($activity->attribute_changes['old']['sessions'])->toHaveCount(2)
        ->and($activity->attribute_changes['attributes']['sessions'])->toHaveCount(2);

    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->group->is($this->a) && $event->timesChanged);
});

it('une las horas que se pegan con el mismo tipo y salón, y no toca un grupo que ya estaba igual', function () {
    Event::fake([GroupScheduleChanged::class]);
    GroupSession::factory()->for($this->b)->on(4, '10:00', '12:00')->create();

    $result = replaceSessions([
        $this->a->id => [new SessionCandidate(2, '07:00', '08:00', $this->room->id), new SessionCandidate(2, '08:00', '09:00', $this->room->id)],
        $this->b->id => [new SessionCandidate(4, '10:00', '11:00'), new SessionCandidate(4, '11:00', '12:00')],
    ]);

    expect(array_keys($result['groups']))->toBe([$this->a->id])
        ->and(replacedSessionsOf($this->a))->toBe(['2 07:00-09:00 LC-1'])
        ->and(Activity::query()->where('subject_id', $this->b->id)->exists())->toBeFalse();

    Event::assertDispatchedTimes(GroupScheduleChanged::class, 1);
});

it('dos grupos del mismo docente intercambian sus horas en una sola escritura', function () {
    GroupSession::factory()->for($this->a)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->b)->on(1, '09:00', '11:00')->create();

    replaceSessions([
        $this->a->id => [new SessionCandidate(1, '09:00', '11:00')],
        $this->b->id => [new SessionCandidate(1, '07:00', '09:00')],
    ]);

    expect(replacedSessionsOf($this->a))->toBe(['1 09:00-11:00'])
        ->and(replacedSessionsOf($this->b))->toBe(['1 07:00-09:00']);
});

it('HC-01: si el docente queda con dos clases a la vez no escribe nada', function () {
    GroupSession::factory()->for($this->a)->on(1, '07:00', '09:00')->create();

    expect(fn () => replaceSessions([$this->b->id => [new SessionCandidate(1, '08:00', '10:00')]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['session'][0])->toBe('HC-01: un docente quedaría con dos clases a la vez el lunes. No se escribió nada.'));

    expect(replacedSessionsOf($this->b))->toBe([])
        ->and(Activity::query()->where('event', ReplaceGroupSessions::EVENT)->exists())->toBeFalse();
});

it('exige las reglas del editor: HC-04 y la carga editable', function () {
    expect(fn () => replaceSessions([$this->a->id => [new SessionCandidate(7, '07:00', '08:00')]]))
        ->toThrow(ValidationException::class);

    $this->load->update(['status' => LoadStatus::Published]);

    expect(fn () => replaceSessions([$this->a->id => [new SessionCandidate(1, '07:00', '08:00')]]))
        ->toThrow(ValidationException::class, 'no se editan horarios');
});
