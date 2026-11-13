<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Actions\Sessions\SessionResult;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AssignSessionRoom (tarea 3.7): asigna o quita el salón de una sesión. Un salón ocupado por otro grupo (HC-02) no se
| guarda y da el mensaje del validador; uno muy chico (HC-05), de otro tipo (HC-06) o bloqueado (HC-11) se guarda y
| queda marcado (confirmado el 7 de noviembre de 2026). Uno de otro departamento se asigna de inmediato y pide el
| préstamo al dueño (HC-10).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->owner = Department::factory()->create(['code' => 'MM', 'name' => 'Metal-Mecánica']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->department->id,
    ]);
    $this->subject = Subject::factory()->create(['code' => 'AED-1026', 'name' => 'Estructura de Datos', 'department_id' => $this->department->id, 'needs_lab' => true]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'code' => 'I', 'capacity' => 30,
    ]);
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id]);
    $this->room = assignRoomOf(['code' => 'CC-05', 'type' => RoomType::SalaComputo, 'capacity' => 32]);
    $this->session = GroupSession::factory()->for($this->group)->practica()->on(2, '15:00', '17:00')->create();
});

function assignRoomOf(array $attributes = []): Room
{
    return Room::factory()->create(['building_id' => test()->building->id, ...$attributes]);
}

function assignRoom(?Room $room, ?GroupSession $session = null): SessionResult
{
    return app(AssignSessionRoom::class)->handle($session ?? test()->session, $room, test()->actor);
}

function otherGroupOfAssign(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$attributes]);
}

it('asigna un salón libre a la sesión y la devuelve con la validación del grupo', function () {
    $result = assignRoom($this->room);

    expect($result->session->room_id)->toBe($this->room->id)
        ->and($result->loanRequested)->toBeFalse()
        ->and(array_filter(['HC-02', 'HC-05', 'HC-06', 'HC-10', 'HC-11'], fn (string $rule): bool => $result->validation->forRule($rule) !== []))->toBe([])
        ->and($this->session->fresh()->room_id)->toBe($this->room->id)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
});

it('deja la bitácora en el grupo con el salón de antes y de después y avisa con GroupScheduleChanged después del commit', function () {
    $heard = [];
    Event::listen(GroupScheduleChanged::class, function (GroupScheduleChanged $event) use (&$heard): void {
        expect($event->timesChanged)->toBeFalse();
        $heard[] = $event->group->id;
    });

    DB::transaction(function () use (&$heard): void {
        assignRoom($this->room);

        expect($heard)->toBe([]);
    });

    $entry = Activity::sole();

    expect($heard)->toBe([$this->group->id])
        ->and($entry)
        ->event->toBe('session_room_assigned')
        ->description->toBe('Asignó el salón CC-05 a la sesión de Estructura de Datos, grupo I del martes de 15:00 a 17:00')
        ->and($entry->causer->is($this->actor))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['day' => 2, 'starts_at' => '15:00', 'ends_at' => '17:00', 'kind' => 'practica', 'room_id' => $this->room->id],
            'old' => ['day' => 2, 'starts_at' => '15:00', 'ends_at' => '17:00', 'kind' => 'practica', 'room_id' => null],
        ]);
});

it('HC-02: no asigna un salón que otro grupo ocupa a esa hora y da el mensaje del validador', function () {
    $other = otherGroupOfAssign(['code' => '3A', 'subject_id' => $this->subject->id]);
    GroupSession::factory()->for($other)->inRoom($this->room)->on(2, '16:00', '18:00')->create();

    expect(fn () => assignRoom($this->room))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['El salón CC-05 ya está ocupado por Estructura de Datos, grupo 3A el martes de 16:00 a 18:00.']))
        ->and($this->session->fresh()->room_id)->toBeNull()
        ->and(Activity::count())->toBe(0);
});

it('HC-02: si otra sesión gana la carrera por el salón, la exclusión de la base lo rechaza con el id de la regla', function () {
    $other = otherGroupOfAssign();
    $room = $this->room;

    GroupSession::updating(function (GroupSession $updating) use ($other, $room): void {
        if ($updating->group_id !== $other->id) {
            GroupSession::factory()->for($other)->inRoom($room)->on(2, '15:00', '16:00')->create();
        }
    });

    expect(fn () => assignRoom($this->room))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['session'])
            ->toBe(['HC-02: el salón CC-05 ya está ocupado el martes entre 15:00 y 17:00.']))
        ->and($this->session->fresh()->room_id)->toBeNull();
});

it('HC-05, HC-06 y HC-11 no detienen el guardado: el salón se asigna y la sesión queda marcada', function (string $rule, Closure $room) {
    $room = $room();

    $result = assignRoom($room);

    expect($this->session->fresh()->room_id)->toBe($room->id)
        ->and($result->validation->forRule($rule))->toHaveCount(1);
})->with([
    'HC-05: más chico que el grupo' => ['HC-05', fn () => assignRoomOf(['type' => RoomType::Laboratorio, 'capacity' => 28])],
    'HC-06: aula para una práctica con laboratorio' => ['HC-06', fn () => assignRoomOf(['type' => RoomType::Aula, 'capacity' => 35])],
    'HC-11: bloqueado a esa hora' => ['HC-11', fn () => tap(assignRoomOf(['type' => RoomType::SalaComputo]), fn (Room $room) => RoomBlock::factory()->create(['room_id' => $room->id, 'day' => 2, 'starts_at' => '16:00', 'ends_at' => '18:00']))],
]);

it('HC-10: un salón de otro departamento se asigna de inmediato y pide el préstamo de la sesión al dueño', function () {
    $foreign = assignRoomOf(['code' => 'MM-L2', 'type' => RoomType::Laboratorio, 'department_id' => $this->owner->id]);

    $result = assignRoom($foreign);
    $loan = InterdepartmentalAgreement::query()->sole();

    expect($result->loanRequested)->toBeTrue()
        ->and($result->validation->forRule('HC-10')[0]->message)->toContain('préstamo pendiente')
        ->and($this->session->fresh()->room_id)->toBe($foreign->id)
        ->and($loan)
        ->status->toBe(AgreementStatus::Proposed)
        ->group_id->toBe($this->group->id)
        ->room_id->toBe($foreign->id)
        ->day->toBe(2)
        ->starts_at->toBe('15:00:00')
        ->ends_at->toBe('17:00:00')
        ->from_department_id->toBe($this->department->id)
        ->to_department_id->toBe($this->owner->id)
        ->requested_by->toBe($this->actor->id);
});

it('HC-10: con préstamos aceptados del grupo que ya cubren la sesión no pide otro', function () {
    $foreign = assignRoomOf(['department_id' => $this->owner->id]);
    InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id, 'room_id' => $foreign->id,
        'from_department_id' => $this->department->id, 'to_department_id' => $this->owner->id, 'day' => 2, 'starts_at' => '15:00', 'ends_at' => '17:00',
    ]);

    $result = assignRoom($foreign);

    expect($result->loanRequested)->toBeFalse()
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(1);
});

it('HC-10: quitar el salón cancela su préstamo y deja la bitácora', function () {
    $foreign = assignRoomOf(['code' => 'MM-L2', 'department_id' => $this->owner->id]);
    assignRoom($foreign);

    $result = assignRoom(null);

    expect($result->session->room_id)->toBeNull()
        ->and(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Cancelled)
        ->and(Activity::query()->where('event', 'session_room_assigned')->latest('id')->first()->description)
        ->toBe('Quitó el salón MM-L2 de la sesión de Estructura de Datos, grupo I del martes de 15:00 a 17:00');
});

it('HC-10: cambiar a otro salón cancela el préstamo del anterior y pide el del nuevo', function () {
    $first = assignRoomOf(['department_id' => $this->owner->id]);
    $second = assignRoomOf(['department_id' => $this->owner->id]);
    assignRoom($first);

    assignRoom($second);

    expect(InterdepartmentalAgreement::query()->where('room_id', $first->id)->sole()->status)->toBe(AgreementStatus::Cancelled)
        ->and(InterdepartmentalAgreement::query()->where('room_id', $second->id)->sole()->status)->toBe(AgreementStatus::Proposed);
});

/**
 * Un préstamo vivo de otro grupo de la materia en el salón, el martes.
 */
function otherGroupLoanOfAssign(Room $room, string $startsAt, string $endsAt, AgreementStatus $status = AgreementStatus::Proposed): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'room_id' => $room->id,
        'group_id' => otherGroupOfAssign(['code' => '3A', 'subject_id' => test()->subject->id])->id,
        'from_department_id' => test()->department->id === $room->department_id ? test()->owner->id : test()->department->id,
        'to_department_id' => $room->department_id, 'day' => 2, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
}

it('HC-10: si otro grupo ya tiene el salón pedido o prestado a esa hora, no lo asigna y dice para quién', function (AgreementStatus $status, string $message) {
    $foreign = assignRoomOf(['code' => 'MM-L2', 'department_id' => $this->owner->id]);
    otherGroupLoanOfAssign($foreign, '16:00', '17:00', $status);

    expect(fn () => assignRoom($foreign))->toThrow(ValidationException::class, $message)
        ->and($this->session->fresh()->room_id)->toBeNull()
        ->and(InterdepartmentalAgreement::query()->where('group_id', $this->group->id)->count())->toBe(0);
})->with([
    'pedido' => [AgreementStatus::Proposed, 'El salón MM-L2 ya está pedido para Estructura de Datos, grupo 3A el martes de 16:00 a 17:00.'],
    'prestado' => [AgreementStatus::Accepted, 'El salón MM-L2 ya está prestado a Estructura de Datos, grupo 3A el martes de 16:00 a 17:00.'],
]);

it('HC-10: tampoco asigna al departamento dueño un salón que prestó a otro grupo a esa hora', function () {
    $own = assignRoomOf(['code' => 'SC-L1', 'department_id' => $this->department->id]);
    otherGroupLoanOfAssign($own, '15:00', '16:00', AgreementStatus::Accepted);

    expect(fn () => assignRoom($own))->toThrow(ValidationException::class, 'El salón SC-L1 ya está prestado a Estructura de Datos, grupo 3A')
        ->and($this->session->fresh()->room_id)->toBeNull();
});

it('HC-10: el hueco bloqueado de otra sesión del grupo en ese salón no detiene la asignación', function () {
    $foreign = assignRoomOf(['code' => 'MM-L2', 'department_id' => $this->owner->id]);
    GroupSession::factory()->for($this->group)->inRoom($foreign)->on(2, '10:00', '12:00')->create();
    otherGroupLoanOfAssign($foreign, '10:00', '12:00');

    $result = assignRoom($foreign);

    expect($this->session->fresh()->room_id)->toBe($foreign->id)
        ->and($result->loanRequested)->toBeTrue()
        ->and($result->loanBlocked)->toBeTrue()
        ->and(InterdepartmentalAgreement::query()->where('group_id', $this->group->id)->live()->pluck('starts_at')->all())->toBe(['15:00:00']);
});

it('rechaza un salón de otro campus o inactivo, sin escribir', function (Closure $room, string $message) {
    expect(fn () => assignRoom($room()))->toThrow(ValidationException::class, $message)
        ->and($this->session->fresh()->room_id)->toBeNull();
})->with([
    'de otro campus' => [fn () => Room::factory()->create(['code' => 'X-1']), 'El salón X-1 es de otro campus.'],
    'inactivo' => [fn () => assignRoomOf(['code' => 'CC-09', 'is_active' => false]), 'El salón CC-09 está inactivo.'],
]);

it('con la carga cerrada o el grupo cancelado no asigna', function (Closure $setUp, string $message) {
    $setUp();

    expect(fn () => assignRoom($this->room))->toThrow(ValidationException::class, $message)
        ->and($this->session->fresh()->room_id)->toBeNull();
})->with([
    'carga cerrada' => [fn () => test()->load->update(['status' => LoadStatus::Closed]), 'está en cerrada: no se editan horarios.'],
    'grupo cancelado' => [fn () => test()->group->update(['status' => 'cancelled']), 'está cancelado'],
]);

it('asignar el mismo salón no escribe, no registra ni avisa', function () {
    $this->session->update(['room_id' => $this->room->id]);
    Event::fake([GroupScheduleChanged::class]);

    $result = assignRoom($this->room);

    expect($result->session->room_id)->toBe($this->room->id)
        ->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});
