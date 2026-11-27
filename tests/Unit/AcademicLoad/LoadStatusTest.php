<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Stage;
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

it('dice el estado dentro de una frase con su etiqueta, de forma gramatical: «ya está publicada», «está en ajustes» (ensayo 8.11, E-07)', function (LoadStatus $status, string $predicate) {
    expect($status->predicate())->toBe($predicate)
        ->and($predicate)->toContain(mb_strtolower($status->label()));
})->with([
    [LoadStatus::Draft, 'está en borrador'],
    [LoadStatus::Proposed, 'está en propuesta'],
    [LoadStatus::Staffing, 'está en asignación'],
    [LoadStatus::Adjusting, 'está en ajustes'],
    [LoadStatus::Closed, 'ya está cerrada'],
    [LoadStatus::Approved, 'ya está aprobada'],
    [LoadStatus::Published, 'ya está publicada'],
    [LoadStatus::Reanalysis, 'está en re-análisis'],
]);

it('solo se asignan docentes en asignación, ajustes y re-análisis', function (LoadStatus $status, bool $accepts) {
    expect($status->acceptsTeacherAssignment())->toBe($accepts);
})->with([
    [LoadStatus::Draft, false],
    [LoadStatus::Proposed, false],
    [LoadStatus::Staffing, true],
    [LoadStatus::Adjusting, true],
    [LoadStatus::Closed, false],
    [LoadStatus::Approved, false],
    [LoadStatus::Published, false],
    [LoadStatus::Reanalysis, true],
]);

it('MN-06: cada estado de la carga abre sus etapas para el jefe y el JPD (supuesto por confirmar)', function (LoadStatus $status, array $stages) {
    expect($status->stages())->toBe($stages);
})->with([
    [LoadStatus::Draft, [Stage::Proposal, Stage::Scheduling, Stage::Staffing]],
    // La bandeja sigue en propuesta: la carga pasa a propuesta al aceptar la primera parte y quedan las de otras carreras y los paralelos.
    [LoadStatus::Proposed, [Stage::Proposal, Stage::Scheduling, Stage::Rooms, Stage::Staffing]],
    [LoadStatus::Staffing, [Stage::Scheduling, Stage::Rooms, Stage::Staffing, Stage::Closing]],
    [LoadStatus::Adjusting, [Stage::Scheduling, Stage::Rooms, Stage::Staffing, Stage::Closing]],
    [LoadStatus::Closed, [Stage::Closing, Stage::Publication]],
    [LoadStatus::Approved, [Stage::Closing, Stage::Publication]],
    [LoadStatus::Published, [Stage::Publication, Stage::Reanalysis]],
    [LoadStatus::Reanalysis, [Stage::Scheduling, Stage::Rooms, Stage::Staffing, Stage::Reanalysis, Stage::Publication]],
]);
