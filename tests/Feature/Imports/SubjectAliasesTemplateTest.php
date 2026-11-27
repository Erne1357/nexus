<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\SubjectAliasesTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `subject_aliases` (R11 de la migración del SII): cada renglón deja una clave con que el SII conoce una
| materia como alias de la materia de Nexus, con el plan donde se vio. La escribe el paso `materias` después de las
| materias, también para las claves que se unificaron en una clave TecNM. Un alias que ya es de otra materia es
| error; a la misma, no hace nada. Nunca desactiva: los alias no tienen `is_active`.
*/

beforeEach(function () {
    $this->subject = Subject::factory()->create(['code' => 'ACF-0901']);
    $this->plan = StudyPlan::factory()->for(Career::factory()->create(['code' => 'ISIC']))->create(['code' => 'ISIC-2010-224']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new SubjectAliasesTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function subjectAliasRow(array $changes = []): array
{
    return (new SubjectAliasesTemplate)->normalize(['code' => ' cd1 ', 'subject' => 'acf-0901', 'plan' => 'isic-2010-224', ...$changes]);
}

it('está registrada en Imports con su clave, su nombre y sus campos', function () {
    $template = app(TemplateRegistry::class)->get('subject_aliases');

    expect($template)->toBeInstanceOf(SubjectAliasesTemplate::class)
        ->and($template->fields())->toBe(['code' => 'clave del SII', 'subject' => 'materia', 'plan' => 'plan'])
        ->and($template->naturalKey())->toBe(['code'])
        ->and($template->deactivatesMissing())->toBeFalse();
});

it('normaliza las claves en mayúsculas y una fila completa no trae errores', function () {
    $row = subjectAliasRow();

    expect($row)->toBe(['code' => 'CD1', 'subject' => 'ACF-0901', 'plan' => 'ISIC-2010-224'])
        ->and($this->template->validate($row, $this->context)->errors)->toBe([]);
});

it('la materia y el plan deben existir por su clave', function () {
    expect($this->template->validate(subjectAliasRow(['subject' => 'NADA']), $this->context)->errors)
        ->toBe(['subject' => ['No hay una materia con la clave NADA.']])
        ->and($this->template->validate(subjectAliasRow(['plan' => 'P-1']), $this->context)->errors)
        ->toBe(['plan' => ['No hay un plan con la clave P-1.']])
        ->and($this->template->validate(subjectAliasRow(['plan' => null]), $this->context)->errors)->toBe([]);
});

it('da de alta el alias con su materia y su plan; repetirlo no hace nada', function () {
    $alias = $this->template->apply(subjectAliasRow(), $this->context);

    expect($alias->wasRecentlyCreated)->toBeTrue()
        ->and(SubjectAlias::query()->sole())
        ->code->toBe('CD1')
        ->subject_id->toBe($this->subject->id)
        ->study_plan_id->toBe($this->plan->id);

    $again = (new SubjectAliasesTemplate)->apply(subjectAliasRow(), $this->context);

    expect($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->wasChanged())->toBeFalse()
        ->and(SubjectAlias::count())->toBe(1);
});

it('un alias que ya es de otra materia es error y apply nunca lo pasa en silencio', function () {
    $other = Subject::factory()->create(['code' => 'CD1']);
    SubjectAlias::query()->create(['code' => 'CD1', 'subject_id' => $other->id]);

    expect($this->template->validate(subjectAliasRow(), $this->context)->errors)
        ->toBe(['code' => ['La clave del SII CD1 ya es de otra materia.']])
        ->and(fn () => $this->template->apply(subjectAliasRow(), $this->context))->toThrow(LogicException::class);
});
