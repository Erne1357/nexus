<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\PrerequisitesTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `prerequisites`, «Seriación» (tarea 1.9): plan, materia y materia requisito, con un tipo opcional. Sin
| tipo, es seriación; si el archivo trae la columna, acepta seriación o correquisito. Es error si alguna de las dos
| materias no está en la retícula del plan, si una materia se pide a sí misma, o si el renglón cierra un ciclo de
| seriación con lo que ya tiene el plan o con renglones anteriores del archivo (confirmado el 29 de octubre de
| 2026). Al terminar se recalcula el avance.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    foreach (['A' => 1, 'B' => 2, 'C' => 3] as $code => $semester) {
        $this->{$code} = Subject::factory()->create(['code' => "ACF-090{$semester}"]);
        placeInPlan($this->plan, $this->{$code}, $semester);
    }
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new PrerequisitesTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function prerequisiteRow(array $changes = []): array
{
    return (new PrerequisitesTemplate)->normalize(['plan' => 'isic-2010-224', 'subject' => 'acf-0902', 'prerequisite' => 'acf-0901', 'kind' => null, ...$changes]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('prerequisites'))
        ->toBeInstanceOf(PrerequisitesTemplate::class)
        ->label()->toBe('Seriación');
});

it('sin tipo es seriación; reconoce seriación y correquisito sin importar acentos, y otro tipo es error', function (?string $kind, string $normalized, bool $valid) {
    $row = prerequisiteRow(['kind' => $kind]);

    expect($row['kind'])->toBe($normalized)
        ->and($this->template->validate($row, $this->context)->errors === [])->toBe($valid);
})->with([
    'sin tipo' => [null, 'seriacion', true],
    'Seriación' => ['Seriación', 'seriacion', true],
    'Correquisito' => ['CORREQUISITO', 'correquisito', true],
    'otro' => ['Optativa', 'Optativa', false],
]);

it('revisa que el plan exista y que las dos materias estén en su retícula', function (array $changes, string $field, string $message) {
    Subject::factory()->create(['code' => 'SCD-1018']);

    expect($this->template->validate(prerequisiteRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'plan que no existe' => [['plan' => 'P-1'], 'plan', 'No hay un plan con la clave P-1.'],
    'materia que no existe' => [['subject' => 'XYZ-0000'], 'subject', 'La materia XYZ-0000 no está en la retícula del plan ISIC-2010-224.'],
    'materia fuera de la retícula' => [['subject' => 'SCD-1018'], 'subject', 'La materia SCD-1018 no está en la retícula del plan ISIC-2010-224.'],
    'requisito fuera de la retícula' => [['prerequisite' => 'SCD-1018'], 'prerequisite', 'La materia SCD-1018 no está en la retícula del plan ISIC-2010-224.'],
    'una materia que se pide a sí misma' => [['prerequisite' => 'ACF-0902'], 'prerequisite', 'La materia ACF-0902 no puede pedirse a sí misma.'],
]);

it('es error si cierra un ciclo de seriación con lo que ya tiene el plan, también de varios pasos', function () {
    seriate($this->plan, $this->B, $this->A);
    seriate($this->plan, $this->C, $this->B);

    expect($this->template->validate(prerequisiteRow(['subject' => 'ACF-0901', 'prerequisite' => 'ACF-0903']), $this->context)->errors)
        ->toBe(['prerequisite' => ['Cierra un ciclo de seriación: ACF-0903 ya pide, en cadena, a ACF-0901.']])
        ->and($this->template->validate(prerequisiteRow(['subject' => 'ACF-0901', 'prerequisite' => 'ACF-0903', 'kind' => 'correquisito']), $this->context)->errors)
        ->toBe([]);
});

it('da de alta la seriación del plan y actualiza su tipo al volver a importarla', function () {
    $created = $this->template->apply(prerequisiteRow(), $this->context);
    $updated = $this->template->apply(prerequisiteRow(['kind' => 'correquisito']), $this->context);

    expect(SubjectPrerequisite::count())->toBe(1)
        ->and($updated->is($created))->toBeTrue()
        ->and($updated->fresh())->study_plan_id->toBe($this->plan->id)->subject_id->toBe($this->B->id)->prerequisite_subject_id->toBe($this->A->id)
        ->kind->toBe(PrerequisiteKind::Correquisito);
});

it('una seriación borrada no se restaura', function () {
    seriate($this->plan, $this->B, $this->A)->delete();

    expect($this->template->validate(prerequisiteRow(), $this->context)->errors)
        ->toBe(['subject' => ['Hay un registro borrado con esta clave; una importación no lo restaura.']]);
});

it('un ciclo entre renglones del mismo archivo se rechaza al aplicar: el renglón posterior queda con error', function () {
    Storage::fake('imports');
    $batch = ImportBatch::factory()->create(['template' => 'prerequisites', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", [
        'clave de plan,materia,materia requisito',
        'ISIC-2010-224,ACF-0902,ACF-0901',
        'ISIC-2010-224,ACF-0901,ACF-0902',
    ]));

    app(ValidateImportBatch::class)->handle($batch);
    app(ApplyImportBatch::class)->handle($batch->fresh(), $this->context->user);
    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows[2]->status)->toBe(RowStatus::Applied)
        ->and($rows[3])->status->toBe(RowStatus::Error)
        ->errors->toBe(['prerequisite' => ['Cierra un ciclo de seriación: ACF-0902 ya pide, en cadena, a ACF-0901.']])
        ->and(SubjectPrerequisite::count())->toBe(1);
});

it('al terminar el lote recalcula el avance de los alumnos, porque cambió la seriación', function () {
    Queue::fake();

    $this->template->afterApply($this->context);

    Queue::assertPushed(RecomputeStudentProgress::class);
});

it('no ofrece desactivar faltantes: quitar una seriación cambia el avance y se hace a mano', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});
