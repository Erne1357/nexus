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
use App\Modules\Scheduling\Actions\Sessions\SessionResult;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateGroupSession (tarea 3.5): mueve una sesión de día u hora y le cambia el tipo, con las mismas reglas que
| colocarla. Conserva su salón si a la nueva hora sigue libre; si otro grupo lo ocupa (HC-02), lo quita y lo dice en
| el resultado (supuesto por confirmar). Un empalme del docente (HC-01) no se guarda.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id,
    ]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => Subject::factory()->create(['name' => 'Cálculo Diferencial', 'department_id' => $this->department->id])->id,
        'code' => 'D',
    ]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => 'LC-1']);
    $this->session = GroupSession::factory()->for($this->group)->inRoom($this->room)->on(1, '07:00', '09:00')->create();
});

function moveSession(GroupSession $session, int $day, string $startsAt, string $endsAt, SessionKind $kind = SessionKind::Teoria): SessionResult
{
    return app(UpdateGroupSession::class)->handle($session, $day, $startsAt, $endsAt, $kind, test()->user);
}

function otherGroupOfMove(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$attributes]);
}

it('mueve la sesión de día y hora, le cambia el tipo y conserva el salón si sigue libre', function () {
    $result = moveSession($this->session, 3, '10:00', '12:00', SessionKind::Practica);

    expect($result->session->id)->toBe($this->session->id)
        ->and($result->roomDropped)->toBeFalse()
        ->and($this->session->fresh())
        ->day->toBe(3)
        ->starts_at->toBe('10:00:00')
        ->ends_at->toBe('12:00:00')
        ->kind->toBe(SessionKind::Practica)
        ->room_id->toBe($this->room->id);
});

it('HC-02: si a la nueva hora otro grupo ocupa el salón, mueve la sesión sin salón y lo dice en el resultado', function () {
    GroupSession::factory()->for(otherGroupOfMove())->inRoom($this->room)->on(2, '08:00', '10:00')->create();

    $result = moveSession($this->session, 2, '09:00', '11:00');

    expect($result->roomDropped)->toBeTrue()
        ->and($result->session->room_id)->toBeNull()
        ->and($result->validation->forRule('HC-02'))->toBe([])
        ->and($this->session->fresh())
        ->day->toBe(2)
        ->starts_at->toBe('09:00:00')
        ->room_id->toBeNull();
});

it('HC-01: no mueve una sesión a una hora en que el docente ya da clase y da el mensaje del validador', function () {
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->group->update(['teacher_id' => $teacher->id]);
    $busy = otherGroupOfMove(['teacher_id' => $teacher->id, 'code' => 'F', 'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
    GroupSession::factory()->for($busy)->on(4, '10:00', '11:00')->create();

    expect(fn () => moveSession($this->session, 4, '09:00', '11:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['Ana Pérez ya da Química, grupo F el jueves de 10:00 a 11:00.']))
        ->and($this->session->fresh())
        ->day->toBe(1)
        ->starts_at->toBe('07:00:00')
        ->and(Activity::count())->toBe(0);
});

it('HC-01: moverla dentro de su propio horario no choca consigo misma', function () {
    $teacher = Teacher::factory()->create();
    $this->group->update(['teacher_id' => $teacher->id]);

    moveSession($this->session, 1, '08:00', '10:00');

    expect($this->session->fresh()->starts_at)->toBe('08:00:00');
});

it('HC-02: si otra sesión gana la carrera por el salón, la exclusión de la base lo rechaza con el id de la regla', function () {
    $other = otherGroupOfMove();
    $room = $this->room;

    GroupSession::updating(function (GroupSession $updating) use ($other, $room): void {
        if ($updating->group_id !== $other->id) {
            GroupSession::factory()->for($other)->inRoom($room)->on(2, '08:00', '09:00')->create();
        }
    });

    expect(fn () => moveSession($this->session, 2, '07:00', '09:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['HC-02: el salón LC-1 ya está ocupado el martes entre 07:00 y 09:00.']))
        ->and($this->session->fresh()->day)->toBe(1);
});

it('une la sesión movida con una vecina del mismo tipo y salón: la movida se queda, la vecina se borra y queda en la bitácora', function () {
    $neighbor = GroupSession::factory()->for($this->group)->inRoom($this->room)->on(2, '09:00', '10:00')->create();

    $result = moveSession($this->session, 2, '07:00', '09:00');

    expect($result->session->id)->toBe($this->session->id)
        ->and($this->session->fresh())
        ->day->toBe(2)
        ->starts_at->toBe('07:00:00')
        ->ends_at->toBe('10:00:00')
        ->room_id->toBe($this->room->id)
        ->and(GroupSession::query()->whereKey($neighbor->id)->exists())->toBeFalse()
        ->and(Activity::sole()->properties->all())->toBe([
            'absorbed' => [['id' => $neighbor->id, 'day' => 2, 'starts_at' => '09:00', 'ends_at' => '10:00', 'kind' => 'teoria', 'room_id' => $this->room->id]],
        ]);
});

it('no une con una vecina sin salón cuando la movida conserva el suyo', function () {
    GroupSession::factory()->for($this->group)->on(2, '09:00', '10:00')->create();

    moveSession($this->session, 2, '07:00', '09:00');

    expect($this->group->sessions()->count())->toBe(2);
});

it('rechaza encimarse con otra sesión del mismo grupo, sin mover', function () {
    GroupSession::factory()->for($this->group)->on(2, '08:00', '10:00')->create();

    expect(fn () => moveSession($this->session, 2, '07:00', '09:00'))
        ->toThrow(ValidationException::class, 'El grupo D ya tiene sesión el martes de 08:00 a 10:00: no se encima otra.')
        ->and($this->session->fresh()->day)->toBe(1);
});

it('HC-04: rechaza moverla fuera de la ventana del campus', function () {
    expect(fn () => moveSession($this->session, 1, '20:00', '22:00'))
        ->toThrow(ValidationException::class, 'HC-04: la sesión debe caer dentro del horario de Campus II')
        ->and($this->session->fresh()->ends_at)->toBe('09:00:00');
});

it('con la carga cerrada o el grupo cancelado no la mueve', function (Closure $setUp, string $message) {
    $setUp($this);

    expect(fn () => moveSession($this->session, 2, '07:00', '09:00'))
        ->toThrow(ValidationException::class, $message)
        ->and($this->session->fresh()->day)->toBe(1);
})->with([
    'carga cerrada' => [fn ($test) => $test->load->update(['status' => LoadStatus::Closed]), 'está en cerrada: no se editan horarios.'],
    'grupo cancelado' => [fn ($test) => $test->group->update(['status' => 'cancelled']), 'El grupo D está cancelado'],
]);

it('sin cambios no escribe, no registra ni avisa', function () {
    Event::fake([GroupScheduleChanged::class]);

    $result = moveSession($this->session, 1, '07:00', '09:00');

    expect($result->session->id)->toBe($this->session->id)
        ->and($result->roomDropped)->toBeFalse()
        ->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('deja la bitácora en el grupo con las horas de antes y de después, y avisa con GroupScheduleChanged', function () {
    Event::fake([GroupScheduleChanged::class]);

    moveSession($this->session, 3, '10:00', '12:00', SessionKind::Practica);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('session_updated')
        ->description->toBe('Movió una sesión de Cálculo Diferencial, grupo D del lunes de 07:00 a 09:00 al miércoles de 10:00 a 12:00')
        ->and($entry->causer->is($this->user))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['day' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'practica', 'room_id' => $this->room->id],
            'old' => ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'teoria', 'room_id' => $this->room->id],
        ]);
    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->group->is($this->group));
});
