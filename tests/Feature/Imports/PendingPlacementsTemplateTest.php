<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\PendingPlacementsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `pending_placements` (D18 y D19 de la migración del SII): los campos de `subjects` sin semestre. Da de
| alta o cambia la materia global igual que `subjects`, la especialidad si viene y el pendiente de retícula; si el
| plan ya tiene la materia en su retícula avisa y no crea pendiente; repetir no duplica.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    $this->department = Department::factory()->create(['code' => '110600']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new PendingPlacementsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function pendingPlacementRow(array $changes = []): array
{
    return (new PendingPlacementsTemplate)->normalize([
        'plan' => 'isic-2010-224', 'code' => ' zzz-1 ', 'name' => 'Materia sin par', 'theory_hours' => '2', 'practice_hours' => '2',
        'credits' => '4', 'department' => '110600', 'specialty' => '', 'specialty_name' => '', 'kind' => 'basica', ...$changes,
    ]);
}

it('está registrada en Imports sin campo de semestre', function () {
    $template = app(TemplateRegistry::class)->get('pending_placements');

    expect($template)->toBeInstanceOf(PendingPlacementsTemplate::class)
        ->and(array_keys($template->fields()))->toBe(['plan', 'code', 'name', 'theory_hours', 'practice_hours', 'credits', 'department', 'specialty', 'specialty_name', 'kind'])
        ->and($template->naturalKey())->toBe(['plan', 'code'])
        ->and($template->deactivatesMissing())->toBeFalse();
});

it('importar un pendiente crea la materia y el pendiente', function () {
    $row = pendingPlacementRow(['specialty' => 'esp-1', 'specialty_name' => 'Especialidad uno']);

    expect($this->template->validate($row, $this->context)->errors)->toBe([]);

    $pending = $this->template->apply($row, $this->context);
    $subject = Subject::query()->where('code', 'ZZZ-1')->sole();

    expect($pending)->toBeInstanceOf(StudyPlanSubjectPending::class)->and($pending->wasRecentlyCreated)->toBeTrue()
        ->and($pending->subject_id)->toBe($subject->id)->and($pending->study_plan_id)->toBe($this->plan->id)
        ->and($pending->specialty->code)->toBe('ESP-1')->and($pending->specialty->name)->toBe('Especialidad uno')
        ->and($pending->resolved_at)->toBeNull()
        ->and($subject)->name->toBe('Materia sin par')->credits->toBe(4)->department_id->toBe($this->department->id);
});

it('repetir el renglón no duplica el pendiente ni la materia', function () {
    $row = pendingPlacementRow();
    $this->template->apply($row, $this->context);
    $again = $this->template->apply($row, $this->context);

    expect($again->wasRecentlyCreated)->toBeFalse()->and($again->wasChanged())->toBeFalse()
        ->and(StudyPlanSubjectPending::count())->toBe(1)->and(Subject::count())->toBe(1);
});

it('cambia la materia global si el renglón trae otros datos', function () {
    $this->template->apply(pendingPlacementRow(), $this->context);
    $again = $this->template->apply(pendingPlacementRow(['name' => 'Otro nombre', 'credits' => '5']), $this->context);

    expect(Subject::query()->where('code', 'ZZZ-1')->sole())->name->toBe('Otro nombre')->credits->toBe(5)
        ->and($this->template->wroteAlongside($again))->toBeTrue()
        ->and(StudyPlanSubjectPending::count())->toBe(1);
});

it('no crea pendiente si la materia ya está en la retícula', function () {
    $subject = Subject::factory()->create(['code' => 'ZZZ-1']);
    StudyPlanSubject::factory()->create(['study_plan_id' => $this->plan->id, 'subject_id' => $subject->id, 'semester' => 3]);
    $row = pendingPlacementRow();

    $issues = $this->template->validate($row, $this->context);
    $this->template->apply($row, $this->context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings['code'][0])->toContain('ya está en la retícula')
        ->and(StudyPlanSubjectPending::count())->toBe(0);
});

it('exige plan y departamento existentes y rechaza un semestre que no es campo', function () {
    $issues = $this->template->validate(pendingPlacementRow(['plan' => 'NADA', 'department' => '999']), $this->context);

    expect($issues->errors)->toHaveKeys(['plan', 'department']);
});

it('una especialidad borrada es error y una materia borrada también', function () {
    $specialty = Specialty::factory()->for($this->plan)->create(['code' => 'ESP-9']);
    $specialty->delete();
    Subject::factory()->create(['code' => 'DEL-1'])->delete();

    expect($this->template->validate(pendingPlacementRow(['specialty' => 'ESP-9']), $this->context)->errors)->toHaveKey('specialty')
        ->and($this->template->validate(pendingPlacementRow(['code' => 'DEL-1']), $this->context)->errors)->toHaveKey('code');
});
