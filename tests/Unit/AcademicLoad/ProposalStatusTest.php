<?php

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| Estados de la propuesta de carrera y de la parte de cada departamento (docs/dominio/modelo-de-datos.md,
| `career_proposals` y `proposal_reviews`), con su etiqueta y el tono de su insignia (DESIGN.md, "Insignias
| de estado"). La propuesta solo se cambia en borrador o regresada (reglas-de-negocio.md, "Propuesta de
| carrera").
*/

it('cada estado de la propuesta tiene su etiqueta, el tono de su insignia y si se puede cambiar', function (ProposalStatus $status, string $label, StatusTone $tone, bool $editable) {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone)
        ->and($status->isEditable())->toBe($editable);
})->with([
    [ProposalStatus::Draft, 'Borrador', StatusTone::Draft, true],
    [ProposalStatus::Submitted, 'Enviada', StatusTone::Info, false],
    [ProposalStatus::Returned, 'Regresada', StatusTone::Error, true],
    [ProposalStatus::Accepted, 'Aceptada', StatusTone::Success, false],
]);

it('cada estado de la parte de un departamento tiene su etiqueta y el tono de su insignia', function (ProposalReviewStatus $status, string $label, StatusTone $tone) {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    [ProposalReviewStatus::Pending, 'Pendiente', StatusTone::Warning],
    [ProposalReviewStatus::Accepted, 'Aceptada', StatusTone::Success],
    [ProposalReviewStatus::Returned, 'Regresada', StatusTone::Error],
]);
