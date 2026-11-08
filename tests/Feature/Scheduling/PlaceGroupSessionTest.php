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
use App\Modules\Scheduling\Actions\Sessions\PlaceGroupSession;
use App\Modules\Scheduling\Actions\Sessions\SessionResult;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\Constraints\Constraint;
use App\Modules\Scheduling\Rules\Constraints\RoomOverlap;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| PlaceGroupSession (tarea 3.5): coloca una sesión sin salón en un grupo. Solo con la carga de su departamento en
| propuesta, asignación o ajustes y el grupo sin cancelar; HC-04 (días, ventana y bloques del campus) se exige antes de
| validar. Un empalme de docente (HC-01) o del mismo grupo no se guarda y da su mensaje; las demás reglas duras se
| guardan y vuelven en el resultado (confirmado el 7 de noviembre de 2026). Un bloque pegado a otra sesión del grupo
| del mismo día, tipo y salón se une con ella.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id,
    ]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => Subject::factory()->create(['name' => 'Cálculo Diferencial', 'department_id' => $this->department->id])->id,
        'code' => 'D',
    ]);
});

function placeSession(Group $group, int $day, string $startsAt, string $endsAt, SessionKind $kind = SessionKind::Teoria): SessionResult
{
    return app(PlaceGroupSession::class)->handle($group, $day, $startsAt, $endsAt, $kind, test()->user);
}

function roomOfPlaceCampus(): Room
{
    return Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => test()->campus->id])->id]);
}

/**
 * Las sesiones del grupo como «día inicio-fin tipo».
 *
 * @return list<string>
 */
function placedSessionsOf(Group $group): array
{
    return $group->sessions()->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => "{$session->day} ".substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5)." {$session->kind->value}")
        ->all();
}

/**
 * Una regla dura de prueba que el validador corre junto a HC-02: marca a todo grupo con sesiones. Representa las
 * reglas duras que la base no impide (HC-03, HC-05, HC-06, HC-10, HC-11, HC-12…).
 */
function withAnotherHardRule(string $rule, string $message): void
{
    app()->extend(RoomOverlap::class, fn (Constraint $inner): Constraint => new class($inner, $rule, $message) implements Constraint
    {
        public function __construct(private readonly Constraint $inner, private readonly string $id, private readonly string $message) {}

        public function rule(): string
        {
            return $this->inner->rule();
        }

        public function check(ScheduleState $state, ValidationScope $scope): iterable
        {
            yield from $this->inner->check($state, $scope);

            foreach ($scope->groupIds() as $groupId) {
                if ($state->sessionsOf($groupId) !== []) {
                    yield Violation::hard($this->id, $this->message, 'group', $groupId, $groupId);
                }
            }
        }
    });
}

it('coloca la sesión sin salón, con el periodo y el campus del grupo, y la devuelve con la validación del grupo', function () {
    $result = placeSession($this->group, 2, '07:00', '09:00', SessionKind::Practica);

    $session = $this->group->sessions()->sole();

    expect($result->session->is($session))->toBeTrue()
        ->and($result->roomDropped)->toBeFalse()
        ->and($result->validation->forRule('HC-01'))->toBe([])
        ->and($session)
        ->day->toBe(2)
        ->starts_at->toBe('07:00:00')
        ->ends_at->toBe('09:00:00')
        ->kind->toBe(SessionKind::Practica)
        ->room_id->toBeNull()
        ->term_id->toBe($this->term->id)
        ->campus_id->toBe($this->campus->id);
});

it('acepta la carga en propuesta, asignación o ajustes', function (LoadStatus $status) {
    $this->load->update(['status' => $status]);

    placeSession($this->group, 1, '07:00', '08:00');

    expect($this->group->sessions()->count())->toBe(1);
})->with([LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting]);

it('rechaza con la carga en cualquier otro estado, sin escribir', function (LoadStatus $status, string $label) {
    $this->load->update(['status' => $status]);

    expect(fn () => placeSession($this->group, 1, '07:00', '08:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(["La carga de Sistemas y Computación en Campus II está en {$label}: no se editan horarios."]))
        ->and($this->group->sessions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'like', 'session_%')->count())->toBe(0);
})->with([
    'borrador' => [LoadStatus::Draft, 'borrador'],
    'cerrada' => [LoadStatus::Closed, 'cerrada'],
    'aprobada' => [LoadStatus::Approved, 'aprobada'],
    'publicada' => [LoadStatus::Published, 'publicada'],
    're-análisis' => [LoadStatus::Reanalysis, 're-análisis'],
]);

it('rechaza si el departamento no tiene carga en el campus y el periodo', function () {
    $this->load->delete();

    expect(fn () => placeSession($this->group, 1, '07:00', '08:00'))
        ->toThrow(ValidationException::class, 'Sistemas y Computación no tiene carga en Campus II en este periodo: no se editan horarios.');
});

it('rechaza un grupo cancelado', function () {
    $this->group->update(['status' => 'cancelled']);

    expect(fn () => placeSession($this->group, 1, '07:00', '08:00'))
        ->toThrow(ValidationException::class, 'El grupo D está cancelado: no se editan horarios.')
        ->and($this->group->sessions()->count())->toBe(0);
});

it('HC-04: rechaza lo que cae fuera de los días, la ventana o los bloques del campus', function (int $day, string $startsAt, string $endsAt, string $message) {
    expect(fn () => placeSession($this->group, $day, $startsAt, $endsAt))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])->toBe([$message]))
        ->and($this->group->sessions()->count())->toBe(0);
})->with([
    'sábado' => [6, '07:00', '08:00', 'HC-04: Campus II no da clases el sábado (lunes a viernes, 7:00 a 21:00).'],
    'antes de abrir' => [1, '06:00', '07:00', 'HC-04: la sesión debe caer dentro del horario de Campus II (lunes a viernes, 7:00 a 21:00).'],
    'después de cerrar' => [1, '20:00', '22:00', 'HC-04: la sesión debe caer dentro del horario de Campus II (lunes a viernes, 7:00 a 21:00).'],
    'a media hora' => [1, '07:30', '08:30', 'HC-04: la sesión debe empezar y terminar en bloques de 60 minutos desde las 07:00.'],
    'fin antes del inicio' => [1, '09:00', '08:00', 'La sesión debe terminar después de empezar.'],
    'hora mal escrita' => [1, '7am', '08:00', 'La hora «7am» no es válida: se escribe como 07:00.'],
]);

it('HC-04: sigue el perfil del campus, no un horario fijo', function () {
    $this->campus->update(['settings' => [...$this->campus->settings, 'days' => [5, 6], 'block_minutes' => 30, 'day_start' => '08:00']]);

    placeSession($this->group, 6, '08:30', '09:00');

    expect(placedSessionsOf($this->group))->toBe(['6 08:30-09:00 teoria'])
        ->and(fn () => placeSession($this->group, 1, '08:00', '09:00'))
        ->toThrow(ValidationException::class, 'HC-04: Campus II no da clases el lunes (viernes y sábado, 8:00 a 21:00).');
});

it('une un bloque pegado a otra sesión del mismo tipo y sin salón: la que existía se extiende', function () {
    $existing = GroupSession::factory()->for($this->group)->on(1, '08:00', '09:00')->create();

    $after = placeSession($this->group, 1, '09:00', '10:00');
    $before = placeSession($this->group, 1, '07:00', '08:00');

    expect(placedSessionsOf($this->group))->toBe(['1 07:00-10:00 teoria'])
        ->and($after->session->id)->toBe($existing->id)
        ->and($before->session->id)->toBe($existing->id);
});

it('une con las dos vecinas: la de antes se extiende, la de después se borra y queda en la bitácora', function () {
    $first = GroupSession::factory()->for($this->group)->on(3, '07:00', '08:00')->create();
    $last = GroupSession::factory()->for($this->group)->on(3, '09:00', '10:00')->create();

    $result = placeSession($this->group, 3, '08:00', '09:00');

    expect(placedSessionsOf($this->group))->toBe(['3 07:00-10:00 teoria'])
        ->and($result->session->id)->toBe($first->id)
        ->and(GroupSession::query()->whereKey($last->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'like', 'session_%')->sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '10:00', 'kind' => 'teoria', 'room_id' => null],
            'old' => ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'kind' => 'teoria', 'room_id' => null],
        ])
        ->and(Activity::query()->where('event', 'like', 'session_%')->sole()->properties->all())->toBe([
            'absorbed' => [['id' => $last->id, 'day' => 3, 'starts_at' => '09:00', 'ends_at' => '10:00', 'kind' => 'teoria', 'room_id' => null]],
        ]);
});

it('une con la de después cuando no hay de antes: esa se extiende hacia atrás', function () {
    $next = GroupSession::factory()->for($this->group)->on(2, '10:00', '12:00')->create();

    $result = placeSession($this->group, 2, '09:00', '10:00');

    expect(placedSessionsOf($this->group))->toBe(['2 09:00-12:00 teoria'])
        ->and($result->session->id)->toBe($next->id);
});

it('no une con una vecina de otro tipo, con otro salón, de otro día ni de otro grupo', function () {
    $room = roomOfPlaceCampus();
    GroupSession::factory()->for($this->group)->practica()->on(1, '07:00', '08:00')->create();
    GroupSession::factory()->for($this->group)->inRoom($room)->on(1, '09:00', '10:00')->create();
    GroupSession::factory()->for($this->group)->on(2, '08:00', '09:00')->create();
    GroupSession::factory()->for(Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]))->on(1, '10:00', '11:00')->create();

    placeSession($this->group, 1, '08:00', '09:00');
    placeSession($this->group, 1, '11:00', '12:00');

    expect(placedSessionsOf($this->group))->toBe([
        '1 07:00-08:00 practica', '1 08:00-09:00 teoria', '1 09:00-10:00 teoria', '1 11:00-12:00 teoria', '2 08:00-09:00 teoria',
    ]);
});

it('rechaza encimarse con otra sesión del mismo grupo, sin escribir', function () {
    GroupSession::factory()->for($this->group)->on(1, '07:00', '09:00')->create();

    expect(fn () => placeSession($this->group, 1, '08:00', '10:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['El grupo D ya tiene sesión el lunes de 07:00 a 09:00: no se encima otra.']))
        ->and(placedSessionsOf($this->group))->toBe(['1 07:00-09:00 teoria'])
        ->and(Activity::query()->where('event', 'like', 'session_%')->count())->toBe(0);
});

it('HC-01: no guarda una sesión que empalma al docente con otro grupo, en cualquier campus, y da el mensaje del validador', function () {
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->group->update(['teacher_id' => $teacher->id]);
    $busy = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $teacher->id, 'code' => 'F',
        'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
    GroupSession::factory()->for($busy)->on(1, '08:00', '10:00')->create();
    Event::fake([GroupScheduleChanged::class]);

    expect(fn () => placeSession($this->group, 1, '07:00', '09:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['Ana Pérez ya da Química, grupo F el lunes de 08:00 a 10:00.']))
        ->and($this->group->sessions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'like', 'session_%')->count())->toBe(0);

    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('HC-01: si otra sesión gana la carrera, la exclusión de la base lo rechaza con el id de la regla', function () {
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->group->update(['teacher_id' => $teacher->id]);
    $busy = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $teacher->id]);

    GroupSession::creating(function (GroupSession $creating) use ($busy): void {
        if ($creating->group_id !== $busy->id) {
            GroupSession::factory()->for($busy)->on(1, '08:00', '09:00')->create();
        }
    });

    expect(fn () => placeSession($this->group, 1, '07:00', '09:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['HC-01: Ana Pérez ya da clase el lunes entre 07:00 y 09:00.']))
        ->and(GroupSession::query()->count())->toBe(0);
});

it('si otra sesión del mismo grupo gana la carrera, la exclusión de la base da un mensaje claro', function () {
    $group = $this->group;

    GroupSession::creating(function (GroupSession $creating) use ($group): void {
        if ($creating->starts_at === '07:00') {
            DB::table('group_sessions')->insert(['group_id' => $group->id, 'day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'kind' => 'teoria']);
        }
    });

    expect(fn () => placeSession($this->group, 1, '07:00', '09:00'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['El grupo D ya tiene otra sesión el lunes entre 07:00 y 09:00: no se encima otra.']));
});

it('HC-03 y las demás reglas duras que la base no impide no detienen el guardado: la sesión queda y la regla vuelve en el resultado', function () {
    withAnotherHardRule('HC-03', 'Se empalma con Física, grupo E, la única opción de su materia en el semestre 1.');

    $result = placeSession($this->group, 1, '07:00', '09:00');

    expect($this->group->sessions()->count())->toBe(1)
        ->and($result->validation->hasHard())->toBeTrue()
        ->and(array_map(fn (Violation $violation): string => $violation->message, $result->validation->forRule('HC-03')))
        ->toBe(['Se empalma con Física, grupo E, la única opción de su materia en el semestre 1.']);
});

it('calcula el resultado con la foto de después de guardar, no con la que leyó antes de escribir', function () {
    $result = placeSession($this->group, 1, '07:00', '08:00');

    expect($result->validation->forRule('HC-12'))->toHaveCount(1);
});

it('deja la bitácora en el grupo con las horas de antes y de después', function () {
    GroupSession::factory()->for($this->group)->on(1, '07:00', '08:00')->create();

    placeSession($this->group, 1, '08:00', '09:00');

    $entry = Activity::query()->where('event', 'like', 'session_%')->sole();

    expect($entry)
        ->event->toBe('session_placed')
        ->description->toBe('Colocó una sesión de Cálculo Diferencial, grupo D el lunes de 08:00 a 09:00')
        ->and($entry->causer->is($this->user))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'teoria', 'room_id' => null],
            'old' => ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'kind' => 'teoria', 'room_id' => null],
        ]);
});

it('una sesión nueva sin vecinas no tiene horas de antes ni sesiones absorbidas en la bitácora', function () {
    placeSession($this->group, 1, '08:00', '09:00');

    expect(Activity::query()->where('event', 'like', 'session_%')->sole()->attribute_changes->toArray())->toBe([
        'attributes' => ['day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'kind' => 'teoria', 'room_id' => null],
    ])
        ->and(Activity::query()->where('event', 'like', 'session_%')->sole()->properties->all())->toBe([]);
});

it('avisa con GroupScheduleChanged solo después del commit', function () {
    $heard = [];
    Event::listen(GroupScheduleChanged::class, function (GroupScheduleChanged $event) use (&$heard): void {
        $heard[] = $event->group->id;
    });

    try {
        DB::transaction(function (): void {
            placeSession($this->group, 1, '07:00', '08:00');

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and($this->group->sessions()->count())->toBe(0);

    placeSession($this->group, 1, '07:00', '08:00');

    expect($heard)->toBe([$this->group->id]);
});
