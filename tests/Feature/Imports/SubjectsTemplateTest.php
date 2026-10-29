<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\SubjectsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `subjects`, «Materias por plan» (tarea 1.9): cada renglón trae una materia en la retícula de un plan.
| Da de alta o actualiza la materia (global, por su clave) y su lugar en la retícula: semestre y especialidad, que
| se crea en el plan si falta. Una materia viene en un renglón por plan: si dos renglones no coinciden en créditos,
| horas o departamento, el posterior es error; si solo cambia el nombre, avisa y se queda el del primero (confirmado
| el 29 de octubre de 2026). Al terminar se recalcula el avance, porque cambió la retícula.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->plan = StudyPlan::factory()->for(Career::factory()->create(['code' => 'ISIC']))->create(['code' => 'ISIC-2010-224']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new SubjectsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function subjectRow(array $changes = []): array
{
    return (new SubjectsTemplate)->normalize([
        'plan' => 'isic-2010-224',
        'code' => 'acf-0901',
        'name' => 'Cálculo Diferencial',
        'semester' => '1',
        'theory_hours' => 3.0,
        'practice_hours' => '2',
        'credits' => 5,
        'department' => 'cb',
        'specialty' => null,
        'specialty_name' => null,
        ...$changes,
    ]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('subjects'))
        ->toBeInstanceOf(SubjectsTemplate::class)
        ->label()->toBe('Materias por plan');
});

it('normaliza claves en mayúsculas y números de Excel, y una fila completa no trae errores', function () {
    $row = subjectRow();
    $issues = $this->template->validate($row, $this->context);

    expect($row)->plan->toBe('ISIC-2010-224')->code->toBe('ACF-0901')->semester->toBe(1)->theory_hours->toBe(3)->practice_hours->toBe(2)
        ->and($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('revisa contra la base el plan y el departamento, y avisa si están inactivos', function () {
    StudyPlan::factory()->inactive()->create(['code' => 'ISIC-2004-296']);
    Department::factory()->inactive()->create(['code' => 'SC']);

    expect($this->template->validate(subjectRow(['plan' => 'P-1']), $this->context)->errors)->toBe(['plan' => ['No hay un plan con la clave P-1.']])
        ->and($this->template->validate(subjectRow(['department' => 'XX']), $this->context)->errors)->toBe(['department' => ['No hay un departamento con la clave XX.']])
        ->and($this->template->validate(subjectRow(['plan' => 'ISIC-2004-296', 'department' => 'SC']), $this->context)->warnings)->toBe([
            'plan' => ['El plan ISIC-2004-296 está inactivo.'],
            'department' => ['El departamento SC está inactivo.'],
        ]);
});

it('da de alta la materia y su lugar en la retícula; en otro plan la reusa y solo agrega su lugar', function () {
    $other = StudyPlan::factory()->for($this->plan->career)->create(['code' => 'ISIC-2004-296']);

    $placement = $this->template->apply(subjectRow(), $this->context);
    $this->template->apply(subjectRow(['plan' => 'ISIC-2004-296', 'semester' => 2]), $this->context);

    $subject = Subject::sole();

    expect($subject)->code->toBe('ACF-0901')->name->toBe('Cálculo Diferencial')->department_id->toBe($this->department->id)
        ->theory_hours->toBe(3)->practice_hours->toBe(2)->credits->toBe(5)->is_active->toBeTrue()
        ->and($placement->fresh())->study_plan_id->toBe($this->plan->id)->subject_id->toBe($subject->id)->semester->toBe(1)->specialty_id->toBeNull()
        ->and(StudyPlanSubject::where('study_plan_id', $other->id)->sole()->semester)->toBe(2);
});

it('actualiza la materia y su semestre al volver a importar, sin duplicar', function () {
    $this->template->apply(subjectRow(), $this->context);

    $placement = $this->template->apply(subjectRow(['semester' => 2, 'credits' => 4]), $this->context);

    expect(Subject::sole()->credits)->toBe(4)
        ->and(StudyPlanSubject::sole()->semester)->toBe(2)
        ->and($placement->wasChanged())->toBeTrue();
});

it('un renglón que solo cambia la materia, y no su lugar en la retícula, cuenta como cambio', function () {
    $this->template->apply(subjectRow(), $this->context);

    $placement = $this->template->apply(subjectRow(['name' => 'Cálculo I']), $this->context);

    expect($placement->wasChanged())->toBeFalse()
        ->and($this->template->wroteAlongside($placement))->toBeTrue();
});

it('crea la especialidad que falta con su clave, y con su nombre si viene; la que ya existe la reusa', function () {
    $this->template->apply(subjectRow(['code' => 'DWB-2101', 'semester' => 7, 'specialty' => 'web', 'specialty_name' => 'Desarrollo Web']), $this->context);
    $this->template->apply(subjectRow(['code' => 'DWB-2102', 'semester' => 8, 'specialty' => 'WEB']), $this->context);
    $this->template->apply(subjectRow(['code' => 'RSG-2101', 'semester' => 7, 'specialty' => 'RED']), $this->context);

    $web = Specialty::where('code', 'WEB')->sole();

    expect(Specialty::count())->toBe(2)
        ->and($web)->name->toBe('Desarrollo Web')->study_plan_id->toBe($this->plan->id)
        ->and(Specialty::where('code', 'RED')->sole()->name)->toBe('RED')
        ->and(StudyPlanSubject::where('specialty_id', $web->id)->count())->toBe(2);
});

it('avisa si la especialidad está inactiva; la nueva de un plan inactivo nace inactiva, y el nombre que trae el archivo la actualiza', function () {
    Specialty::factory()->for($this->plan)->create(['code' => 'RED', 'is_active' => false]);
    $old = StudyPlan::factory()->inactive()->create(['code' => 'ISIC-2004-296']);

    $warnings = $this->template->validate(subjectRow(['specialty' => 'RED']), $this->context)->warnings;
    $this->template->apply(subjectRow(['plan' => 'ISIC-2004-296', 'specialty' => 'WEB']), $this->context);
    $this->template->apply(subjectRow(['plan' => 'ISIC-2004-296', 'code' => 'DWB-2101', 'specialty' => 'WEB', 'specialty_name' => 'Desarrollo Web']), $this->context);

    expect($warnings)->toBe(['specialty' => ['La especialidad RED del plan ISIC-2010-224 está inactiva.']])
        ->and(Specialty::where(['study_plan_id' => $old->id, 'code' => 'WEB'])->sole())->is_active->toBeFalse()->name->toBe('Desarrollo Web');
});

it('si el SII ya no trae la especialidad de una materia que la tenía, la deja y avisa: quitarla es una edición a mano', function () {
    $this->template->apply(subjectRow(['specialty' => 'WEB']), $this->context);

    $issues = $this->template->validate(subjectRow(), $this->context);
    $this->template->apply(subjectRow(), $this->context);

    expect($issues->warnings)->toBe(['specialty' => ['El SII ya no trae especialidad para ACF-0901 en ISIC-2010-224: se queda en WEB; quitarla es una edición a mano en la retícula.']])
        ->and(StudyPlanSubject::sole()->specialty_id)->toBe(Specialty::where('code', 'WEB')->value('id'));
});

it('no pone topes que no están en las reglas: solo créditos y horas no negativos y semestre desde 1', function () {
    expect($this->template->validate(subjectRow(['credits' => 50, 'theory_hours' => 45, 'semester' => 21]), $this->context)->errors)->toBe([])
        ->and($this->template->validate(subjectRow(['credits' => -1, 'semester' => 0]), $this->context)->errors)->toHaveKeys(['credits', 'semester']);
});

it('lo borrado no se restaura: materia, especialidad o materia quitada de la retícula son error', function () {
    Subject::factory()->create(['code' => 'ACF-0902'])->delete();
    Specialty::factory()->for($this->plan)->create(['code' => 'OLD'])->delete();
    $removed = Subject::factory()->create(['code' => 'ACF-0903']);
    placeInPlan($this->plan, $removed)->delete();

    expect($this->template->validate(subjectRow(['code' => 'ACF-0902']), $this->context)->errors)
        ->toBe(['code' => ['Hay una materia borrada con la clave ACF-0902; una importación no la restaura.']])
        ->and($this->template->validate(subjectRow(['specialty' => 'OLD']), $this->context)->errors)
        ->toBe(['specialty' => ['La especialidad OLD del plan ISIC-2010-224 está borrada; una importación no la restaura.']])
        ->and($this->template->validate(subjectRow(['code' => 'ACF-0903']), $this->context)->errors)
        ->toBe(['code' => ['La materia ACF-0903 se quitó de la retícula del plan ISIC-2010-224; una importación no la vuelve a poner.']]);
});

it('una materia inactiva se actualiza y sigue inactiva, con advertencia', function () {
    Subject::factory()->inactive()->create(['code' => 'ACF-0901']);

    $issues = $this->template->validate(subjectRow(), $this->context);
    $this->template->apply(subjectRow(), $this->context);

    expect($issues->warnings)->toBe(['code' => ['La materia ACF-0901 está inactiva: se actualizan sus datos, pero sigue inactiva.']])
        ->and(Subject::sole()->is_active)->toBeFalse();
});

it('avisa si otra materia con otra clave ya tiene el mismo nombre, sin unificarlas', function () {
    Subject::factory()->create(['code' => 'SCC-1010', 'name' => 'cálculo diferencial']);

    expect($this->template->validate(subjectRow(), $this->context)->warnings)
        ->toBe(['name' => ['La materia SCC-1010 tiene el mismo nombre con otra clave; no se unifican.']]);
});

it('en un archivo, los renglones de una materia deben coincidir en créditos, horas y departamento; el nombre solo avisa', function () {
    Storage::fake('imports');
    Department::factory()->create(['code' => 'SC']);
    foreach (['ISIC-2004-296', 'IIND-2010-227', 'IGEM-2009-201', 'IELC-2010-211'] as $code) {
        StudyPlan::factory()->create(['code' => $code]);
    }
    $batch = ImportBatch::factory()->create(['template' => 'subjects', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", [
        'clave de plan,clave de materia,nombre,semestre,horas teoría,horas práctica,créditos,departamento',
        'ISIC-2010-224,ACF-0901,Cálculo Diferencial,1,3,2,5,CB',
        'ISIC-2004-296,ACF-0901,Cálculo Diferencial,1,3,2,4,CB',
        'IIND-2010-227,ACF-0901,Cálculo Diferencial,1,4,2,5,CB',
        'IGEM-2009-201,ACF-0901,Cálculo Diferencial,1,3,2,5,SC',
        'IELC-2010-211,ACF-0901,Cálculo I,1,3,2,5,CB',
    ]));

    app(ValidateImportBatch::class)->handle($batch);
    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows[2]->status)->toBe(RowStatus::Valid)
        ->and($rows[3]->errors)->toBe(['credits' => ['No coincide con la fila 2, que trae «5».']])
        ->and($rows[4]->errors)->toBe(['theory_hours' => ['No coincide con la fila 2, que trae «3».']])
        ->and($rows[5]->errors)->toBe(['department' => ['No coincide con la fila 2, que trae «CB».']])
        ->and($rows[6])->status->toBe(RowStatus::Warning)
        ->warnings->toBe(['name' => ['No coincide con la fila 2: se queda «Cálculo Diferencial».']]);
});

it('en un archivo, avisa si dos materias nuevas traen el mismo nombre con otra clave', function () {
    Storage::fake('imports');
    StudyPlan::factory()->create(['code' => 'IIND-2010-227']);
    $batch = ImportBatch::factory()->create(['template' => 'subjects', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", [
        'clave de plan,clave de materia,nombre,semestre,horas teoría,horas práctica,créditos,departamento',
        'ISIC-2010-224,ACF-0901,Cálculo Diferencial,1,3,2,5,CB',
        'IIND-2010-227,MAT-0101,cálculo diferencial,1,3,2,5,CB',
    ]));

    app(ValidateImportBatch::class)->handle($batch);
    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows[2]->status)->toBe(RowStatus::Valid)
        ->and($rows[3])->status->toBe(RowStatus::Warning)
        ->warnings->toBe(['name' => ['La fila 2 trae el mismo nombre con otra clave (ACF-0901); no se unifican.']]);
});

it('al terminar el lote recalcula el avance de los alumnos, porque cambió la retícula', function () {
    Queue::fake();

    $this->template->afterApply($this->context);

    Queue::assertPushed(RecomputeStudentProgress::class);
});

it('no ofrece desactivar faltantes: quitar una materia de la retícula cambia el avance y se hace a mano', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});
