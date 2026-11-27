<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\Term;

/*
| Tarea 2.5: AcademicLoad le dice al tablero de avance de validación el estado de cada carga (evento con reporte
| LoadStatusesRequested), sin que CatalogValidation lo importe.
*/

it('reporta el estado de la carga de cada departamento y modalidad del periodo, y solo de las modalidades pedidas (M1)', function () {
    $term = Term::factory()->create();
    $presencial = modalityOf('PRES');
    $ead = modalityOf('EAD');
    $sis = Department::factory()->create();
    AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => $presencial->id, 'department_id' => $sis->id, 'status' => LoadStatus::Proposed]);
    AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => $ead->id, 'department_id' => $sis->id, 'status' => LoadStatus::Draft]);

    $event = new LoadStatusesRequested($term, [$presencial->id]);
    event($event);

    expect($event->for($sis->id, $presencial->id))->toBe(['label' => 'Propuesta', 'tone' => LoadStatus::Proposed->tone()])
        ->and($event->for($sis->id, $ead->id))->toBeNull()
        ->and(LoadStatus::Draft->tone())->toBe(StatusTone::Draft);
});

it('no reporta nada si no se pide ninguna modalidad', function () {
    $event = new LoadStatusesRequested(Term::factory()->create(), []);
    event($event);

    expect($event->for('a', 'b'))->toBeNull();
});
