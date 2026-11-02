<?php

use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| Estado de lo que una carrera le pide a un departamento, en su bandeja (reglas-de-negocio.md, "Propuesta de
| carrera"): sin enviar mientras no hay parte del departamento, y si la hay, el de la parte. Pendientes primero
| (supuesto por confirmar).
*/

it('cada estado tiene su etiqueta y el tono de su insignia, en el orden de la bandeja', function (InboxStatus $status, string $label, StatusTone $tone) {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    [InboxStatus::Pending, 'Pendiente', StatusTone::Warning],
    [InboxStatus::Returned, 'Regresada', StatusTone::Error],
    [InboxStatus::Accepted, 'Aceptada', StatusTone::Success],
    [InboxStatus::NotSubmitted, 'Sin enviar', StatusTone::Draft],
]);

it('sale del estado de la parte, o sin enviar si no hay parte', function (?ProposalReviewStatus $part, InboxStatus $status) {
    expect(InboxStatus::ofPart($part))->toBe($status);
})->with([
    'sin parte' => [null, InboxStatus::NotSubmitted],
    'pendiente' => [ProposalReviewStatus::Pending, InboxStatus::Pending],
    'aceptada' => [ProposalReviewStatus::Accepted, InboxStatus::Accepted],
    'regresada' => [ProposalReviewStatus::Returned, InboxStatus::Returned],
]);

it('ordena los pendientes primero, luego regresadas, aceptadas y sin enviar', function () {
    expect(array_map(fn (InboxStatus $status): int => $status->order(), InboxStatus::cases()))->toBe([0, 1, 2, 3]);
});
