<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de los acuerdos entre departamentos (docs/dominio/modelo-de-datos.md, `interdepartmental_agreements`):
| un préstamo de salón es un salón del campus, un día y unas horas para un grupo; un grupo compartido es el cupo de
| otra carrera en un grupo. Nacen propuestos; quien decide y cuándo van juntos; rechazar lleva motivo. Un salón no se
| presta dos veces a la misma hora, y una carrera no tiene dos acuerdos vivos en el mismo grupo.
*/

it('el préstamo nace propuesto y conoce su periodo, campus, departamentos, salón y quién lo pidió', function () {
    $agreement = InterdepartmentalAgreement::factory()->create()->fresh();

    expect($agreement->kind)->toBe(AgreementKind::RoomLoan)
        ->and($agreement->status)->toBe(AgreementStatus::Proposed)
        ->and($agreement->term)->toBeInstanceOf(Term::class)
        ->and($agreement->campus)->toBeInstanceOf(Campus::class)
        ->and($agreement->fromDepartment)->toBeInstanceOf(Department::class)
        ->and($agreement->toDepartment)->toBeInstanceOf(Department::class)
        ->and($agreement->room)->toBeInstanceOf(Room::class)
        ->and($agreement->requester)->toBeInstanceOf(User::class)
        ->and($agreement->decided_by)->toBeNull()
        ->and($agreement->decided_at)->toBeNull()
        ->and($agreement->starts_at)->toBe('09:00:00')
        ->and($agreement->ends_at)->toBe('11:00:00');
});

it('el grupo compartido conoce la carrera y sus lugares, sin salón ni horas', function () {
    $agreement = InterdepartmentalAgreement::factory()->sharedGroup(seats: 8)->create()->fresh();

    expect($agreement->kind)->toBe(AgreementKind::SharedGroup)
        ->and($agreement->career)->toBeInstanceOf(Career::class)
        ->and($agreement->max_seats)->toBe(8)
        ->and($agreement->room_id)->toBeNull()
        ->and($agreement->day)->toBeNull();
});

it('un acuerdo es entre dos departamentos distintos', function () {
    $department = Department::factory()->create();

    expect(fn () => InterdepartmentalAgreement::factory()->sharedGroup()->create([
        'from_department_id' => $department->id,
        'to_department_id' => $department->id,
    ]))->toThrow(QueryException::class, 'interdepartmental_agreements_departments_check');
});

it('acepta sus dos tipos y sus cuatro estados y rechaza otros', function () {
    foreach (AgreementStatus::cases() as $status) {
        InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create();
    }
    InterdepartmentalAgreement::factory()->create();

    expect(InterdepartmentalAgreement::query()->pluck('status')->unique()->values()->all())->toEqualCanonicalizing(AgreementStatus::cases())
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->update(['kind' => 'swap'])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_kind_check')
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('status', 'accepted')->update(['status' => 'approved'])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_status_check');
});

it('un préstamo pide salón, día y horas para un grupo, y no lleva carrera ni lugares', function (array $change) {
    $agreement = InterdepartmentalAgreement::factory()->create();

    expect(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update($change))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_shape_check');
})->with([
    'sin salón' => [['room_id' => null]],
    'sin día' => [['day' => null]],
    'sin inicio' => [['starts_at' => null]],
    'sin grupo' => [['group_id' => null]],
    'con lugares' => [['max_seats' => 5]],
]);

it('un grupo compartido pide grupo, carrera y lugares, y no lleva salón ni horas', function (array $change) {
    $agreement = InterdepartmentalAgreement::factory()->sharedGroup()->create();

    expect(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update($change))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_shape_check');
})->with([
    'sin carrera' => [['career_id' => null]],
    'sin lugares' => [['max_seats' => null]],
    'sin grupo' => [['group_id' => null]],
    'con día' => [['day' => 2]],
]);

it('los lugares son más de cero, el día va de lunes a sábado y el préstamo termina después de empezar', function (string $column, mixed $value, string $check) {
    $agreement = $column === 'max_seats'
        ? InterdepartmentalAgreement::factory()->sharedGroup()->create()
        : InterdepartmentalAgreement::factory()->create();

    expect(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update([$column => $value]))
        ->toThrow(QueryException::class, $check);
})->with([
    'cero lugares' => ['max_seats', 0, 'interdepartmental_agreements_seats_check'],
    'domingo' => ['day', 7, 'interdepartmental_agreements_day_check'],
    'día cero' => ['day', 0, 'interdepartmental_agreements_day_check'],
    'termina al empezar' => ['ends_at', '09:00', 'interdepartmental_agreements_time_check'],
]);

it('el salón prestado es del campus del acuerdo', function () {
    $otherCampusRoom = Room::factory()->create(['building_id' => Building::factory()->create()->id]);
    $agreement = InterdepartmentalAgreement::factory()->create();

    expect(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update(['room_id' => $otherCampusRoom->id]))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_room_campus_foreign');
});

it('rechazado lleva motivo; propuesto no lleva quién ni cuándo decidió, y decidido lleva los dos', function () {
    $agreement = InterdepartmentalAgreement::factory()->create();
    $decider = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update(['status' => 'rejected', 'comment' => '  ', 'decided_by' => $decider->id, 'decided_at' => now()])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_rejected_comment_check')
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update(['status' => 'accepted'])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_decision_check')
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update(['decided_by' => $decider->id, 'decided_at' => now()])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_decision_check')
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('id', $agreement->id)->update(['status' => 'accepted', 'decided_at' => now()])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_decision_check');
});

it('un salón no se presta dos veces a la misma hora del mismo día y periodo; contiguo, rechazado o cancelado sí', function () {
    $loan = InterdepartmentalAgreement::factory()->create(['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $same = $loan->only(['term_id', 'campus_id', 'room_id', 'from_department_id', 'to_department_id', 'day']);

    InterdepartmentalAgreement::factory()->create([...$same, 'starts_at' => '11:00', 'ends_at' => '12:00', 'group_id' => (string) Str::uuid7()]);
    InterdepartmentalAgreement::factory()->rejected()->create([...$same, 'starts_at' => '10:00', 'ends_at' => '12:00']);
    InterdepartmentalAgreement::factory()->inStatus(AgreementStatus::Cancelled)->create([...$same, 'starts_at' => '10:00', 'ends_at' => '12:00']);
    InterdepartmentalAgreement::factory()->create([...$same, 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00']);
    InterdepartmentalAgreement::factory()->create([...$same, 'term_id' => Term::factory()->create()->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

    expect(fn () => InterdepartmentalAgreement::factory()->accepted()->create([...$same, 'starts_at' => '10:00', 'ends_at' => '12:00']))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_room_no_overlap');
});

it('una carrera tiene a lo más un acuerdo vivo por grupo; uno rechazado o cancelado no cuenta', function () {
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create();
    $same = $shared->only(['term_id', 'campus_id', 'from_department_id', 'to_department_id', 'group_id', 'career_id']);

    InterdepartmentalAgreement::factory()->sharedGroup()->rejected()->create($same);
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus(AgreementStatus::Cancelled)->create($same);

    expect(fn () => InterdepartmentalAgreement::factory()->sharedGroup()->accepted()->create($same))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_shared_unique');
});
