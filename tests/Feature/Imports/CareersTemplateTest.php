<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\CareersTemplate;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `careers` (tarea 1.9): las carreras del SII por clave, con su departamento y el campus donde se
| ofrecen. Una carrera que se ofrece en dos campus viene en un renglón por campus: cada renglón da de alta la
| carrera si falta y su oferta en ese campus (`career_campus`). No ofrece "desactivar faltantes": son globales
| y la exportación puede ser de un solo campus (confirmado el 29 de octubre de 2026).
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SC']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new CareersTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function careerRow(array $changes = []): array
{
    return (new CareersTemplate)->normalize(['code' => 'isic', 'name' => 'Ingeniería en Sistemas Computacionales', 'department' => 'sc', 'campus' => 'c2', ...$changes]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('careers'))
        ->toBeInstanceOf(CareersTemplate::class)
        ->label()->toBe('Carreras');
});

it('una fila con departamento y campus que existen no trae errores', function () {
    $issues = $this->template->validate(careerRow(), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('revisa contra la base el departamento y el campus', function (array $changes, string $field, string $message) {
    expect($this->template->validate(careerRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'departamento que no existe' => [['department' => 'XX'], 'department', 'No hay un departamento con la clave XX.'],
    'campus que no existe' => [['campus' => 'C9'], 'campus', 'No hay un campus con la clave C9.'],
]);

it('da de alta la carrera y su oferta en el campus del renglón', function () {
    $career = $this->template->apply(careerRow(), $this->context);

    expect($career->fresh())->code->toBe('ISIC')->department_id->toBe($this->department->id)->is_active->toBeTrue()
        ->and(CareerCampus::where('career_id', $career->id)->pluck('campus_id')->all())->toBe([$this->c2->id]);
});

it('un renglón por campus: la misma carrera en otro campus agrega su oferta sin duplicar la carrera, y no es una fila repetida', function () {
    $this->template->apply(careerRow(), $this->context);
    $this->template->apply(careerRow(['campus' => 'C1']), $this->context);
    $this->template->apply(careerRow(), $this->context);

    expect(Career::count())->toBe(1)
        ->and(CareerCampus::count())->toBe(2)
        ->and($this->template->keyFor(careerRow(), $this->context))->not->toBe($this->template->keyFor(careerRow(['campus' => 'C1']), $this->context));
});

it('actualiza el nombre y el departamento de la carrera por su clave', function () {
    $other = Department::factory()->create(['code' => 'CB']);
    $this->template->apply(careerRow(), $this->context);

    $career = $this->template->apply(careerRow(['name' => 'Sistemas Computacionales', 'department' => 'CB']), $this->context);

    expect($career->fresh())->name->toBe('Sistemas Computacionales')->department_id->toBe($other->id);
});

it('una oferta inactiva se queda inactiva, con advertencia; una borrada es error, porque importar no restaura', function () {
    $career = Career::factory()->for($this->department)->create(['code' => 'ISIC']);
    CareerCampus::create(['career_id' => $career->id, 'campus_id' => $this->c2->id, 'is_active' => false]);
    CareerCampus::create(['career_id' => $career->id, 'campus_id' => $this->c1->id])->delete();

    $inactive = $this->template->validate(careerRow(), $this->context);
    $this->template->apply(careerRow(), $this->context);

    expect($inactive->errors)->toBe([])
        ->and($inactive->warnings)->toBe(['campus' => ['La carrera ISIC ya no se ofrece en C2: se actualizan sus datos, pero su oferta sigue inactiva.']])
        ->and(CareerCampus::where('campus_id', $this->c2->id)->sole()->is_active)->toBeFalse()
        ->and($this->template->validate(careerRow(['campus' => 'C1']), $this->context)->errors)
        ->toBe(['campus' => ['La oferta de la carrera ISIC en C1 está borrada; una importación no la restaura.']]);
});

it('no ofrece desactivar faltantes: las carreras son globales y la exportación puede ser de un campus', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});

it('busca cada departamento y campus una sola vez por corrida', function () {
    DB::enableQueryLog();

    foreach (['ISIC', 'IIND', 'IGEM'] as $code) {
        $row = careerRow(['code' => $code]);
        $this->template->validate($row, $this->context);
        $this->template->apply($row, $this->context);
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "(departments|campuses)"/', $sql) === 1);

    expect($lookups)->toHaveCount(2);
});

it('un renglón que solo agrega la oferta en otro campus cuenta como cambio de la carrera; repetirlo, no', function () {
    $this->template->apply(careerRow(), $this->context);

    $career = $this->template->apply(careerRow(['campus' => 'C1']), $this->context);
    $alongside = $this->template->wroteAlongside($career);
    $again = $this->template->apply(careerRow(['campus' => 'C1']), $this->context);

    expect($career->wasRecentlyCreated)->toBeFalse()
        ->and($career->wasChanged())->toBeFalse()
        ->and($alongside)->toBeTrue()
        ->and($this->template->wroteAlongside($again))->toBeFalse();
});

it('avisa si el departamento o el campus están inactivos, y la oferta de una carrera inactiva nace inactiva', function () {
    Department::factory()->inactive()->create(['code' => 'CB']);
    $this->c1->update(['is_active' => false]);
    Career::factory()->for($this->department)->inactive()->create(['code' => 'ISIC']);

    $issues = $this->template->validate(careerRow(['department' => 'CB', 'campus' => 'C1']), $this->context);
    $this->template->apply(careerRow(), $this->context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toBe([
            'code' => ['El registro está inactivo: se actualizan sus datos, pero sigue inactivo.'],
            'department' => ['El departamento CB está inactivo.'],
            'campus' => ['El campus C1 está inactivo.'],
        ])
        ->and(CareerCampus::sole()->is_active)->toBeFalse();
});

it('en un archivo, el departamento de una carrera debe coincidir en todos sus renglones; el nombre solo avisa y se queda el del primero', function () {
    Storage::fake('imports');
    Department::factory()->create(['code' => 'CB']);
    Campus::factory()->create(['code' => 'C3']);
    $batch = ImportBatch::factory()->create(['template' => 'careers', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", [
        'clave,nombre,departamento,campus',
        'ISIC,Ingeniería en Sistemas,SC,C2',
        'ISIC,Ingeniería en Sistemas,CB,C1',
        'ISIC,Sistemas Computacionales,SC,C3',
    ]));

    app(ValidateImportBatch::class)->handle($batch);
    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows[2]->status)->toBe(RowStatus::Valid)
        ->and($rows[3])->status->toBe(RowStatus::Error)
        ->errors->toBe(['department' => ['No coincide con la fila 2, que trae «SC».']])
        ->and($rows[4])->status->toBe(RowStatus::Warning)
        ->warnings->toBe(['name' => ['No coincide con la fila 2: se queda «Ingeniería en Sistemas».']])
        ->and($rows[4]->normalized['name'])->toBe('Ingeniería en Sistemas');
});
