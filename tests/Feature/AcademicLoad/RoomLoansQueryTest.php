<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/*
| Lo que el validador de horarios lee de los préstamos de salón (HC-10): si un préstamo aceptado cubre una sesión
| de un departamento en un salón ajeno, cuál es el préstamo vivo de esa sesión (para decir «pendiente» o «no hay
| préstamo») y los préstamos vivos de un periodo en un campus, para validar toda la carga sin una consulta por sesión.
*/

function roomLoan(array $attributes = [], AgreementStatus $status = AgreementStatus::Accepted): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->inStatus($status)->create(['day' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', ...$attributes]);
}

it('HC-10: un préstamo aceptado cubre una sesión del departamento que lo pidió dentro de sus horas', function () {
    $loan = roomLoan();
    $query = app(RoomLoansQuery::class);

    expect($query->covers($loan->term_id, $loan->room_id, $loan->from_department_id, 2, '09:00', '12:00'))->toBeTrue()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->from_department_id, 2, '10:00', '11:00'))->toBeTrue()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->from_department_id, 2, '11:00', '13:00'))->toBeFalse()
        ->and($query->covers($loan->term_id, $loan->room_id, $loan->from_department_id, 3, '09:00', '10:00'))->toBeFalse()
        ->and($query->covers($loan->term_id, $loan->room_id, Department::factory()->create()->id, 2, '09:00', '10:00'))->toBeFalse()
        ->and($query->covers(Term::factory()->create()->id, $loan->room_id, $loan->from_department_id, 2, '09:00', '10:00'))->toBeFalse();
});

it('HC-10: un préstamo propuesto, rechazado o cancelado no cubre la sesión', function (AgreementStatus $status) {
    $loan = roomLoan(status: $status);

    expect(app(RoomLoansQuery::class)->covers($loan->term_id, $loan->room_id, $loan->from_department_id, 2, '09:00', '10:00'))->toBeFalse();
})->with([AgreementStatus::Proposed, AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('el préstamo de una sesión es el aceptado que la cubre o, si no hay, el propuesto; nunca uno rechazado o cancelado', function () {
    $pending = roomLoan(['starts_at' => '15:00', 'ends_at' => '17:00'], AgreementStatus::Proposed);
    $same = $pending->only(['term_id', 'room_id', 'from_department_id']);
    $query = app(RoomLoansQuery::class);

    expect($query->loanFor($pending->term_id, $pending->room_id, $pending->from_department_id, 2, '15:00', '17:00')?->is($pending))->toBeTrue()
        ->and($query->loanFor($pending->term_id, $pending->room_id, $pending->from_department_id, 2, '09:00', '10:00'))->toBeNull();

    $pending->forceFill(['status' => AgreementStatus::Rejected, 'comment' => 'Lo usa otro grupo.', 'decided_by' => $pending->requested_by, 'decided_at' => now()])->save();
    $accepted = roomLoan([...$same, 'campus_id' => $pending->campus_id, 'to_department_id' => $pending->to_department_id, 'starts_at' => '15:00', 'ends_at' => '17:00']);

    expect($query->loanFor($pending->term_id, $pending->room_id, $pending->from_department_id, 2, '16:00', '17:00')?->is($accepted))->toBeTrue();
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
