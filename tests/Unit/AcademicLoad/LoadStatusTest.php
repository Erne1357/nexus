<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| Estados de la carga académica (docs/dominio/modelo-de-datos.md, `academic_loads`), con su etiqueta y
| el tono de su insignia (DESIGN.md, "Insignias de estado").
*/

it('cada estado de la carga tiene su etiqueta y el tono de su insignia', function (LoadStatus $status, string $label, StatusTone $tone) {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    [LoadStatus::Draft, 'Borrador', StatusTone::Draft],
    [LoadStatus::Proposed, 'Propuesta', StatusTone::Info],
    [LoadStatus::Staffing, 'Asignación', StatusTone::Info],
    [LoadStatus::Adjusting, 'Ajustes', StatusTone::Info],
    [LoadStatus::Closed, 'Cerrada', StatusTone::Success],
    [LoadStatus::Approved, 'Aprobada', StatusTone::Success],
    [LoadStatus::Published, 'Publicada', StatusTone::SuccessSolid],
    [LoadStatus::Reanalysis, 'Re-análisis', StatusTone::Warning],
]);
