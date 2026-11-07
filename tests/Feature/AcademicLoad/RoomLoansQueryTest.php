<?php

use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Str;

/*
| Lo que el validador de horarios lee de los préstamos de salón (HC-10): si los préstamos aceptados de un grupo cubren
| una sesión en un salón ajeno, cuál es el préstamo vivo que la toca (para decir «pendiente» o «no hay préstamo») y los
| préstamos vivos de un periodo en un campus, para validar toda la carga sin una consulta por sesión.
*/

function roomLoan(array $attributes = [], AgreementStatus $status = AgreementStatus::Accepted): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->inStatus($status)->create(['day' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', ...$attributes]);
}

/**
 * Otro préstamo del mismo grupo, salón, periodo y departamentos.
 */
function sameGroupLoan(InterdepartmentalAgreement $loan, array $attributes, AgreementStatus $status = AgreementStatus::Accepted): InterdepartmentalAgreement
{
    return roomLoan([...$loan->only(['term_id', 'campus_id', 'room_id', 'group_id', 'from_department_id', 'to_department_id']), ...$attributes], $status);
}

it('HC-10: un préstamo aceptado cubre las sesiones de su grupo dentro de sus horas, ese día y periodo', function () {
    $loan = roomLoan();
    $query = app(RoomLoansQuery::class);

    expect($query->covers($loan->term_id, $loan->room_id, $loan->group_id, 2, '09:00', '12:00'))->toBeTrue()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->group_id, 2, '10:00', '11:00'))->toBeTrue()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->group_id, 2, '11:00', '13:00'))->toBeFalse()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->group_id, 3, '09:00', '10:00'))->toBeFalse()
        ->and($query->covers($loan->term_id, $loan->room_id, (string) Str::uuid7(), 2, '09:00', '10:00'))->toBeFalse()
        ->and($query->covers(Term::factory()->create()->id, $loan->room_id, $loan->group_id, 2, '09:00', '10:00'))->toBeFalse();
});

it('HC-10: préstamos aceptados contiguos del grupo cubren juntos la sesión; con un hueco, no', function () {
    $first = roomLoan(['starts_at' => '09:00', 'ends_at' => '10:00']);
    sameGroupLoan($first, ['starts_at' => '10:00', 'ends_at' => '11:00']);
    sameGroupLoan($first, ['starts_at' => '12:00', 'ends_at' => '13:00']);
    $query = app(RoomLoansQuery::class);

    expect($query->covers($first->term_id, $first->room_id, $first->group_id, 2, '09:00', '11:00'))->toBeTrue()
        ->and($query->covers($first->term_id, $first->room_id, $first->group_id, 2, '09:00', '13:00'))->toBeFalse()
        ->and($query->covers($first->term_id, $first->room_id, $first->group_id, 2, '08:00', '10:00'))->toBeFalse();
});

it('HC-10: un préstamo propuesto, rechazado o cancelado no cubre la sesión', function (AgreementStatus $status) {
    $loan = roomLoan(status: $status);

    expect(app(RoomLoansQuery::class)->covers($loan->term_id, $loan->room_id, $loan->group_id, 2, '09:00', '10:00'))->toBeFalse();
})->with([AgreementStatus::Proposed, AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('el préstamo vivo que toca la sesión es primero el propuesto; nunca uno rechazado o cancelado', function () {
    $accepted = roomLoan(['starts_at' => '09:00', 'ends_at' => '10:00']);
    $pending = sameGroupLoan($accepted, ['starts_at' => '10:00', 'ends_at' => '11:00'], AgreementStatus::Proposed);
    sameGroupLoan($accepted, ['starts_at' => '11:00', 'ends_at' => '12:00'], AgreementStatus::Rejected);
    $query = app(RoomLoansQuery::class);

    expect($query->loanFor($accepted->term_id, $accepted->room_id, $accepted->group_id, 2, '09:00', '11:00')?->is($pending))->toBeTrue()
        ->and($query->loanFor($accepted->term_id, $accepted->room_id, $accepted->group_id, 2, '09:00', '10:00')?->is($accepted))->toBeTrue()
        ->and($query->loanFor($accepted->term_id, $accepted->room_id, $accepted->group_id, 2, '11:00', '12:00'))->toBeNull()
        ->and($query->loanFor($accepted->term_id, $accepted->room_id, (string) Str::uuid7(), 2, '09:00', '11:00'))->toBeNull();
});

it('los préstamos vivos de un periodo en un campus, sin los de otro periodo o campus ni los grupos compartidos', function () {
    $accepted = roomLoan();
    $pending = roomLoan(['term_id' => $accepted->term_id, 'campus_id' => $accepted->campus_id, 'day' => 4], AgreementStatus::Proposed);
    roomLoan(['term_id' => $accepted->term_id, 'campus_id' => $accepted->campus_id, 'day' => 5], AgreementStatus::Rejected);
    roomLoan(['term_id' => Term::factory()->create()->id, 'campus_id' => $accepted->campus_id]);
    roomLoan(['term_id' => $accepted->term_id, 'campus_id' => Campus::factory()->create()->id]);
    InterdepartmentalAgreement::factory()->sharedGroup()->accepted()->create(['term_id' => $accepted->term_id, 'campus_id' => $accepted->campus_id]);

    expect(app(RoomLoansQuery::class)->liveFor($accepted->term_id, $accepted->campus_id)->modelKeys())
        ->toEqualCanonicalizing([$accepted->id, $pending->id]);
});
