<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\StudyPlansTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `study_plans` (tarea 1.9): los planes del SII por clave, con su carrera, su versión y si está vigente.
| La exportación no trae nombre: si el archivo no lo trae, el plan se llama como su clave. Un plan que deja de
| estar vigente no se desactiva al importar: avisa, porque importar nunca desactiva por sí solo. Un plan no cambia
| de carrera por importación: sus alumnos cambiarían de carrera con él. No ofrece "desactivar faltantes".
*/

beforeEach(function () {
    $this->career = Career::factory()->create(['code' => 'ISIC']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new StudyPlansTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function studyPlanRow(array $changes = []): array
{
    return (new StudyPlansTemplate)->normalize(['code' => 'isic-2010-224', 'career' => 'isic', 'name' => null, 'version' => '2010', 'current' => 'Sí', ...$changes]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('study_plans'))
        ->toBeInstanceOf(StudyPlansTemplate::class)
        ->label()->toBe('Planes de estudio');
});

it('reconoce si está vigente sin importar mayúsculas ni acentos; un valor que no reconoce es error', function (mixed $value, ?bool $current) {
    $row = studyPlanRow(['current' => $value]);

    expect($row['current'])->toBe($current ?? $row['current'])
        ->and($this->template->validate($row, $this->context)->errors === [])->toBe($current !== null || $value === null);
})->with([
    'Sí' => ['Sí', true],
    'S' => ['s', true],
    'Vigente' => ['VIGENTE', true],
    'No' => ['no', false],
    'No vigente' => ['No vigente', false],
    'vacío' => [null, null],
    'otro' => ['Quizá', null],
]);

it('revisa contra la base la carrera, y avisa si está inactiva', function () {
    Career::factory()->inactive()->create(['code' => 'IIND']);

    expect($this->template->validate(studyPlanRow(['career' => 'XYZ']), $this->context)->errors)->toBe(['career' => ['No hay una carrera con la clave XYZ.']])
        ->and($this->template->validate(studyPlanRow(['career' => 'IIND']), $this->context)->warnings)->toBe(['career' => ['La carrera IIND está inactiva.']]);
});

it('da de alta el plan con su carrera; sin nombre en el archivo se llama como su clave, y uno no vigente nace inactivo', function () {
    $current = $this->template->apply(studyPlanRow(), $this->context);
    $old = $this->template->apply(studyPlanRow(['code' => 'ISIC-2004-296', 'version' => '2004', 'current' => 'No']), $this->context);

    expect($current->fresh())->code->toBe('ISIC-2010-224')->name->toBe('ISIC-2010-224')->career_id->toBe($this->career->id)->version->toBe('2010')->is_active->toBeTrue()
        ->and($old->fresh()->is_active)->toBeFalse();
});

it('actualiza el nombre y la versión por clave, y no borra el nombre si el archivo no lo trae', function () {
    $this->template->apply(studyPlanRow(['name' => 'Ingeniería en Sistemas 2010']), $this->context);

    $plan = $this->template->apply(studyPlanRow(['version' => '2010-R']), $this->context);

    expect(StudyPlan::count())->toBe(1)
        ->and($plan->fresh())->name->toBe('Ingeniería en Sistemas 2010')->version->toBe('2010-R');
});

it('un plan activo que el SII ya no marca vigente sigue activo, con advertencia: importar no desactiva', function () {
    $this->template->apply(studyPlanRow(), $this->context);
    $row = studyPlanRow(['current' => 'No']);

    $issues = $this->template->validate($row, $this->context);
    $plan = $this->template->apply($row, $this->context);

    expect($issues->warnings)->toBe(['current' => ['El SII ya no marca vigente el plan ISIC-2010-224: sigue activo; desactívalo a mano si ya no se ofrece.']])
        ->and($plan->fresh()->is_active)->toBeTrue();
});

it('un plan no cambia de carrera por importación: sus alumnos cambiarían de carrera con él', function () {
    Career::factory()->create(['code' => 'IIND']);
    $this->template->apply(studyPlanRow(), $this->context);

    expect($this->template->validate(studyPlanRow(['career' => 'IIND']), $this->context)->errors)
        ->toBe(['career' => ['El plan ISIC-2010-224 es de la carrera ISIC; cambiarlo de carrera es una edición a mano.']]);
});

it('no ofrece desactivar faltantes: los planes son globales y la exportación puede ser de un campus', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});
