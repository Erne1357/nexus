<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\PendingPrerequisitesTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `pending_prerequisites`, «Seriación por validar» (migración de la seriación del SII; decisión del usuario del
| 10 de noviembre de 2026, confirmada): un requisito con una materia en «Por validar» del plan se guarda aparte, ligado
| al plan, y entra solo a la seriación cuando las dos materias quedan en la retícula. Plan, materia, requisito, tipo y
| retícula del SII; no revisa ciclos (se revisan al aplicarse). Repetirla no duplica y no toca uno que ya entró.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'IIND-2004']);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901']);
    $this->algebra = Subject::factory()->create(['code' => 'ACF-0903']);
    placeInPlan($this->plan, $this->calculus, 1);
    placeInPlan($this->plan, $this->algebra, 2);
    $this->physics = Subject::factory()->create(['code' => 'AE22']);
    $this->drawing = Subject::factory()->create(['code' => 'AE9']);
    StudyPlanSubjectPending::factory()->for($this->plan)->for($this->physics, 'subject')->create();
    StudyPlanSubjectPending::factory()->for($this->plan)->for($this->drawing, 'subject')->create();
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new PendingPrerequisitesTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function pendingPrerequisiteRow(array $changes = []): array
{
    return (new PendingPrerequisitesTemplate)->normalize(['plan' => 'iind-2004', 'subject' => 'ae22', 'prerequisite' => 'acf-0901', 'kind' => 'seriacion', 'sii_grid' => ' A/3 ', ...$changes]);
}

it('está registrada en Imports con su clave, su nombre y sus campos', function () {
    $template = app(TemplateRegistry::class)->get('pending_prerequisites');

    expect($template)->toBeInstanceOf(PendingPrerequisitesTemplate::class)
        ->and($template->label())->toBe('Seriación por validar')
        ->and(array_keys($template->fields()))->toBe(['plan', 'subject', 'prerequisite', 'kind', 'sii_grid'])
        ->and($template->naturalKey())->toBe(['subject', 'prerequisite', 'plan'])
        ->and($template->deactivatesMissing())->toBeFalse();
});

it('guarda el requisito que espera cuando una de sus materias está en «Por validar», o las dos', function (string $subject, string $prerequisite, string $kind) {
    $row = pendingPrerequisiteRow(['subject' => $subject, 'prerequisite' => $prerequisite, 'kind' => $kind]);

    expect($this->template->validate($row, $this->context)->errors)->toBe([]);

    $waiting = $this->template->apply($row, $this->context);

    expect($waiting)->toBeInstanceOf(StudyPlanPrerequisitePending::class)->wasRecentlyCreated->toBeTrue()
        ->and($waiting->fresh())
        ->study_plan_id->toBe($this->plan->id)
        ->subject->code->toBe(strtoupper($subject))
        ->prerequisite->code->toBe(strtoupper($prerequisite))
        ->kind->toBe(PrerequisiteKind::from($kind === 'Correquisito' ? 'correquisito' : 'seriacion'))
        ->sii_grid->toBe('A/3')
        ->applied_at->toBeNull();
})->with([
    'la materia espera' => ['ae22', 'acf-0901', 'seriacion'],
    'el requisito espera' => ['acf-0903', 'ae22', 'Correquisito'],
    'las dos esperan' => ['ae22', 'ae9', 'seriacion'],
]);

it('revisa el plan, las materias y el tipo', function (array $changes, string $field, string $message) {
    Subject::factory()->create(['code' => 'SCD-1018']);

    expect($this->template->validate(pendingPrerequisiteRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'plan que no existe' => [['plan' => 'P-1'], 'plan', 'No hay un plan con la clave P-1.'],
    'materia que no existe' => [['subject' => 'XYZ-0000'], 'subject', 'La materia XYZ-0000 no está en la retícula ni en «Por validar» del plan IIND-2004.'],
    'materia fuera del plan' => [['subject' => 'SCD-1018'], 'subject', 'La materia SCD-1018 no está en la retícula ni en «Por validar» del plan IIND-2004.'],
    'requisito fuera del plan' => [['prerequisite' => 'SCD-1018'], 'prerequisite', 'La materia SCD-1018 no está en la retícula ni en «Por validar» del plan IIND-2004.'],
    'a sí misma' => [['prerequisite' => 'AE22'], 'prerequisite', 'La materia AE22 no puede pedirse a sí misma.'],
    'las dos en la retícula' => [['subject' => 'ACF-0903'], 'subject', 'Las dos materias ya están en la retícula del plan IIND-2004: impórtalo como seriación.'],
    'otro tipo' => [['kind' => 'P'], 'kind', 'El tipo debe ser seriación o correquisito.'],
]);

it('una materia resuelta en «Por validar» ya está en la retícula, no esperando', function () {
    StudyPlanSubjectPending::query()->where('subject_id', $this->physics->id)->update(['resolved_at' => now()]);

    expect($this->template->validate(pendingPrerequisiteRow(), $this->context)->errors)
        ->toBe(['subject' => ['La materia AE22 no está en la retícula ni en «Por validar» del plan IIND-2004.']]);
});

it('repetir el renglón no duplica; uno sin aplicar toma el tipo y la retícula del SII nuevos', function () {
    $this->template->apply(pendingPrerequisiteRow(), $this->context);
    $same = $this->template->apply(pendingPrerequisiteRow(), $this->context);

    expect($same->wasRecentlyCreated || $same->wasChanged())->toBeFalse();

    $changed = $this->template->apply(pendingPrerequisiteRow(['kind' => 'correquisito', 'sii_grid' => 'A2/4']), $this->context);

    expect($changed->wasChanged())->toBeTrue()
        ->and(StudyPlanPrerequisitePending::query()->sole())->kind->toBe(PrerequisiteKind::Correquisito)->sii_grid->toBe('A2/4');
});

it('uno que ya entró a la seriación no se toca y avisa', function () {
    $applied = $this->template->apply(pendingPrerequisiteRow(), $this->context);
    $applied->forceFill(['applied_at' => now()])->save();
    $row = pendingPrerequisiteRow(['kind' => 'correquisito']);

    expect($this->template->validate($row, $this->context)->warnings)->toBe(['subject' => ['El requisito ACF-0901 de AE22 ya entró a la seriación del plan IIND-2004: no se cambia.']]);

    $again = $this->template->apply($row, $this->context);

    expect($again->wasChanged())->toBeFalse()
        ->and($applied->fresh()->kind)->toBe(PrerequisiteKind::Seriacion);
});
