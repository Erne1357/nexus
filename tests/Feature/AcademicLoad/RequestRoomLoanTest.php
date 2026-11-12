<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\RequestRoomLoan;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| RequestRoomLoan: un departamento pide el préstamo de un salón de otro para un día y unas horas de un grupo
| (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10). Es idempotente y deja la exclusión de la base como
| última palabra cuando dos préstamos vivos chocan a la misma hora.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->from = Department::factory()->create(['code' => 'SIS']);
    $this->owner = Department::factory()->create(['code' => 'CB']);
    $this->groupId = (string) Str::uuid7();
    $this->room = Room::factory()->create([
        'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id,
        'department_id' => $this->owner->id,
        'code' => 'LAB1',
    ]);
});

function requestLoan(array $overrides = []): InterdepartmentalAgreement
{
    $t = test();
    $args = [
        'term' => $t->term, 'campus' => $t->campus, 'from' => $t->from, 'groupId' => $t->groupId, 'room' => $t->room,
        'day' => 1, 'startsAt' => '09:00', 'endsAt' => '11:00', 'actor' => $t->actor,
        ...$overrides,
    ];

    return app(RequestRoomLoan::class)->handle(...$args);
}

/**
 * @return array<string, list<string>>|null
 */
function requestLoanErrors(array $overrides = []): ?array
{
    try {
        requestLoan($overrides);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('crea un préstamo propuesto dirigido al dueño del salón', function () {
    $loan = requestLoan()->fresh();

    expect($loan->kind)->toBe(AgreementKind::RoomLoan)
        ->and($loan->status)->toBe(AgreementStatus::Proposed)
        ->and($loan->from_department_id)->toBe($this->from->id)
        ->and($loan->to_department_id)->toBe($this->owner->id)
        ->and($loan->term_id)->toBe($this->term->id)
        ->and($loan->campus_id)->toBe($this->campus->id)
        ->and($loan->group_id)->toBe($this->groupId)
        ->and($loan->room_id)->toBe($this->room->id)
        ->and($loan->day)->toBe(1)
        ->and($loan->starts_at)->toBe('09:00:00')
        ->and($loan->ends_at)->toBe('11:00:00')
        ->and($loan->requested_by)->toBe($this->actor->id)
        ->and($loan->decided_at)->toBeNull();
});

it('el préstamo nace con la modalidad del catálogo de su campus (ampliación de la ronda 2)', function () {
    $this->campus->update(['modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);

    expect(requestLoan()->fresh()->modality->code)->toBe('EAD');
});

it('deja bitácora con quién lo pidió y qué pidió', function () {
    $loan = requestLoan();

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($loan->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('requested')
        ->and($activity->attribute_changes['attributes'])->toMatchArray(['status' => 'proposed', 'room_id' => $this->room->id, 'day' => 1])
        ->and($activity->description)->toBe('SIS pidió a CB el salón LAB1 el lunes de 9:00 a 11:00 en C2 para el periodo 20271');
});

it('es idempotente: el mismo préstamo vivo se regresa sin escribir', function () {
    $first = requestLoan();
    $again = requestLoan();

    expect($again->is($first))->toBeTrue()
        ->and(InterdepartmentalAgreement::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1);
});

it('un préstamo aceptado que cubre la sesión se regresa sin escribir', function () {
    $accepted = InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->from->id,
        'to_department_id' => $this->owner->id, 'room_id' => $this->room->id, 'group_id' => $this->groupId, 'starts_at' => '08:00', 'ends_at' => '12:00',
    ]);

    $loan = requestLoan();

    expect($loan->is($accepted))->toBeTrue()
        ->and(InterdepartmentalAgreement::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

it('un préstamo aceptado de otro grupo no cubre la sesión: pide el suyo si el salón está libre', function () {
    InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->from->id,
        'to_department_id' => $this->owner->id, 'room_id' => $this->room->id, 'starts_at' => '13:00', 'ends_at' => '15:00',
    ]);

    expect(requestLoan()->status)->toBe(AgreementStatus::Proposed)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(2);
});

it('dos préstamos aceptados contiguos del grupo cubren juntos la sesión y se regresa sin escribir', function () {
    foreach ([['09:00', '10:00'], ['10:00', '11:00']] as [$start, $end]) {
        InterdepartmentalAgreement::factory()->accepted()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->from->id,
            'to_department_id' => $this->owner->id, 'room_id' => $this->room->id, 'group_id' => $this->groupId, 'starts_at' => $start, 'ends_at' => $end,
        ]);
    }

    requestLoan();

    expect(InterdepartmentalAgreement::query()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe(0);
});

it('un préstamo rechazado o cancelado no cuenta: pide uno nuevo', function (AgreementStatus $status) {
    InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->from->id,
        'to_department_id' => $this->owner->id, 'room_id' => $this->room->id, 'group_id' => $this->groupId,
    ]);

    expect(requestLoan()->status)->toBe(AgreementStatus::Proposed)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(2);
})->with([AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('si otro préstamo vivo ocupa el salón a esa hora, rechaza sobre room_id sin abortar la transacción de quien llama', function () {
    InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'room_id' => $this->room->id,
        'to_department_id' => $this->owner->id, 'starts_at' => '10:00', 'ends_at' => '12:00',
    ]);

    DB::transaction(function () {
        expect(requestLoanErrors())->toBe(['room_id' => ['El salón ya está prestado o pedido a esa hora.']])
            ->and(InterdepartmentalAgreement::query()->count())->toBe(1);
    });
    expect(Activity::query()->count())->toBe(0);
});

it('rechaza un salón sin dueño, del propio departamento o de otro campus, sin escribir', function (Closure $change, string $message) {
    $change($this->room, $this);

    expect(requestLoanErrors())->toBe(['room_id' => [$message]])
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'sin dueño' => [fn (Room $room) => $room->update(['department_id' => null]), 'El salón es de tu departamento o no tiene dueño: no necesita préstamo.'],
    'del propio departamento' => [fn (Room $room, $t) => $room->update(['department_id' => $t->from->id]), 'El salón es de tu departamento o no tiene dueño: no necesita préstamo.'],
    'de otro campus' => [function (Room $room) {
        $other = Campus::factory()->create();
        $room->update(['building_id' => Building::factory()->create(['campus_id' => $other->id])->id, 'campus_id' => $other->id]);
    }, 'El salón es de otro campus.'],
]);

it('rechaza el día y las horas inválidos sobre su campo', function (array $overrides, array $errors) {
    $found = requestLoanErrors($overrides);

    if ($errors === []) {
        expect($found)->toBeNull();

        return;
    }

    expect($found)->toHaveKeys(array_keys($errors))
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
})->with([
    'domingo' => [['day' => 7], ['day' => 1]],
    'día cero' => [['day' => 0], ['day' => 1]],
    'fin igual al inicio' => [['endsAt' => '09:00'], ['starts_at' => 1]],
    'hora inválida' => [['startsAt' => '25:00'], ['starts_at' => 1]],
    'texto' => [['endsAt' => 'tarde'], ['ends_at' => 1]],
    'sin cero inicial' => [['startsAt' => '9:00', 'endsAt' => '10:00'], []],
    'fin antes del inicio' => [['startsAt' => '11:00', 'endsAt' => '09:00'], ['starts_at' => 1]],
]);

it('9:00 y 09:00 son la misma hora: la comparación es por valor', function () {
    expect(requestLoanErrors(['startsAt' => '9:00', 'endsAt' => '10:00']))->toBeNull()
        ->and(requestLoanErrors(['startsAt' => '10:00', 'endsAt' => '9:00']))->toHaveKey('starts_at');
});

it('avisa con AgreementRequested solo después del commit', function () {
    Event::fake([AgreementRequested::class]);

    $loan = requestLoan();

    Event::assertDispatched(AgreementRequested::class, fn (AgreementRequested $event): bool => $event->agreement->is($loan));
});

it('no avisa si la transacción externa se revierte', function () {
    Event::fake([AgreementRequested::class]);

    try {
        DB::transaction(function () {
            requestLoan();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(AgreementRequested::class);
    expect(InterdepartmentalAgreement::query()->count())->toBe(0);
});
