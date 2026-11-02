<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/*
| Tarea 2.5: AcademicLoad le dice al tablero de avance de validación el estado de cada carga (evento con reporte
| LoadStatusesRequested), sin que CatalogValidation lo importe.
*/

it('reporta el estado de la carga de cada departamento y campus del periodo, y solo de los campus pedidos', function () {
    $term = Term::factory()->create();
    $c2 = Campus::factory()->create();
    $c1 = Campus::factory()->create();
    $sis = Department::factory()->create();
    AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $c2->id, 'department_id' => $sis->id, 'status' => LoadStatus::Proposed]);
    AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $c1->id, 'department_id' => $sis->id, 'status' => LoadStatus::Draft]);

    $event = new LoadStatusesRequested($term, [$c2->id]);
    event($event);

    expect($event->for($sis->id, $c2->id))->toBe(['label' => 'Propuesta', 'tone' => LoadStatus::Proposed->tone()])
        ->and($event->for($sis->id, $c1->id))->toBeNull()
        ->and(LoadStatus::Draft->tone())->toBe(StatusTone::Draft);
});

it('no reporta nada si no se pide ningún campus', function () {
    $event = new LoadStatusesRequested(Term::factory()->create(), []);
    event($event);

    expect($event->for('a', 'b'))->toBeNull();
});
