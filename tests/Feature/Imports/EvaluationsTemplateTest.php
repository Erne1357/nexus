<?php

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\EvaluationKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Imports\Templates\EvaluationsTemplate;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `evaluations` (tarea 1.13): el puntaje de la evaluación docente por número de empleado, periodo
| del SII y tipo. La escala es de 1 a 5 y el CHECK admite de 0 a 5 (confirmado el 30 de octubre de 2026).
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1234567']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new EvaluationsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function evaluationRow(array $changes = []): array
{
    return (new EvaluationsTemplate)->normalize([
        'employee_number' => '1234567',
        'term_code' => '20241',
        'kind' => 'ESTUDIANTIL',
        'score' => '4.5',
        ...$changes,
    ]);
}

it('está registrada en Imports y no ofrece desactivar faltantes', function () {
    expect(app(TemplateRegistry::class)->get('evaluations'))
        ->toBeInstanceOf(EvaluationsTemplate::class)
        ->label()->toBe('Evaluación docente')
        ->deactivatesMissing()->toBeFalse();
});

it('una fila válida no trae errores', function () {
    expect($this->template->validate(evaluationRow(), $this->context)->errors)->toBe([]);
});

it('revisa la fila: docente, periodo, tipo y puntaje de 0 a 5', function (array $changes, string $field, string $message) {
    expect($this->template->validate(evaluationRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'docente que no existe' => [['employee_number' => '9999999'], 'employee_number', 'No hay un docente con el número de empleado 9999999.'],
    'periodo con otro formato' => [['term_code' => 'ENE-JUN 2024'], 'term_code', 'El periodo debe ser el código del SII: año y 1, 2 o 3, como 20251.'],
    'tipo desconocido' => [['kind' => 'X'], 'kind', 'El tipo debe ser estudiantil o departamental.'],
    'puntaje de 5.01' => [['score' => '5.01'], 'score', 'El puntaje debe estar entre 0 y 5.'],
    'puntaje de -1' => [['score' => '-1'], 'score', 'El puntaje debe estar entre 0 y 5.'],
    'puntaje con texto' => [['score' => 'alto'], 'score', 'El puntaje debe estar entre 0 y 5.'],
    'puntaje con tres decimales' => [['score' => '4.555'], 'score', 'El puntaje admite hasta dos decimales.'],
    'sin puntaje' => [['score' => null], 'score', 'El campo puntaje es obligatorio.'],
]);

it('el tipo se lee por nombre, sin importar mayúsculas ni acentos', function (string $name, EvaluationKind $kind) {
    $this->template->apply(evaluationRow(['kind' => $name]), $this->context);

    expect(TeacherEvaluation::sole()->kind)->toBe($kind);
})->with([
    ['ESTUDIANTIL', EvaluationKind::Estudiantil],
    ['Departamental', EvaluationKind::Departamental],
]);

it('el puntaje con coma, 4,5, se lee 4.5; los extremos 0 y 5 valen', function () {
    expect(evaluationRow(['score' => '4,5'])['score'])->toBe(4.5)
        ->and($this->template->validate(evaluationRow(['score' => '0']), $this->context)->errors)->toBe([])
        ->and($this->template->validate(evaluationRow(['score' => '5']), $this->context)->errors)->toBe([]);

    $this->template->apply(evaluationRow(['score' => '4,5']), $this->context);

    expect(TeacherEvaluation::sole())->score->toBe('4.50')->teacher_id->toBe($this->teacher->id);
});

it('aplicar dos veces no duplica ni cambia nada', function () {
    $first = $this->template->apply(evaluationRow(), $this->context);
    $second = $this->template->apply(evaluationRow(), $this->context);

    expect(TeacherEvaluation::count())->toBe(1)
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->wasChanged())->toBeFalse();
});

it('un puntaje nuevo del mismo docente, periodo y tipo actualiza la fila; otro tipo es otra fila', function () {
    $this->template->apply(evaluationRow(['score' => '4']), $this->context);
    $this->template->apply(evaluationRow(['score' => '3.5']), $this->context);
    $this->template->apply(evaluationRow(['kind' => 'departamental']), $this->context);

    expect(TeacherEvaluation::count())->toBe(2)
        ->and(TeacherEvaluation::where('kind', 'estudiantil')->sole()->score)->toBe('3.50');
});

it('una evaluación borrada con la misma clave es error', function () {
    TeacherEvaluation::factory()->create(['teacher_id' => $this->teacher->id])->delete();

    expect($this->template->validate(evaluationRow(), $this->context)->errors)->toHaveKey('employee_number');
});

it('el origen SII se escribe al crear y reaplicar no lo pisa', function () {
    $this->template->apply(evaluationRow(), $this->context);
    TeacherEvaluation::query()->update(['source' => 'manual']);
    $this->template->apply(evaluationRow(['score' => '3']), $this->context);

    expect(TeacherEvaluation::sole())->source->toBe('manual')->score->toBe('3.00');
});

it('el fixture de evaluaciones se lee con sus encabezados y valida con 0 errores', function () {
    foreach (['9000001', '9000002'] as $number) {
        Teacher::factory()->create(['employee_number' => $number]);
    }
    $lines = array_map('str_getcsv', file(base_path('tests/Fixtures/imports/evaluations.csv'), FILE_IGNORE_NEW_LINES), array_fill(0, 4, ','), array_fill(0, 4, '"'), array_fill(0, 4, ''));
    $header = array_shift($lines);

    expect($header)->toBe(array_values($this->template->fields()));

    foreach ($lines as $line) {
        $row = $this->template->normalize(array_combine(array_keys($this->template->fields()), $line));
        expect($this->template->validate($row, $this->context)->errors)->toBe([]);
    }
});
