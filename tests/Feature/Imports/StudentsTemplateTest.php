<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\StudentsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| Plantilla `students` (tarea 1.4): alumnos del SII por número de control, con su carrera, su plan (de esa
| carrera) y su campus, que vienen en el archivo. Un estatus que Nexus no conoce no detiene la importación:
| el alumno entra sin estatus y con advertencia (confirmado el 29 de octubre de 2026). Al terminar el lote se
| recalcula el progreso.
*/

beforeEach(function () {
    $this->career = Career::factory()->create(['code' => 'ISIC']);
    $this->plan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new StudentsTemplate;
});

/**
 * Una fila normalizada de alumno, con lo que se le cambie.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function studentRow(array $changes = []): array
{
    return (new StudentsTemplate)->normalize([
        'control_number' => '19111234',
        'name' => 'Ana López',
        'career' => 'ISIC',
        'study_plan' => 'ISIC-2010-224',
        'campus' => 'C2',
        'semester' => 3,
        'status' => 'activo',
        ...$changes,
    ]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('students'))
        ->toBeInstanceOf(StudentsTemplate::class)
        ->label()->toBe('Alumnos');
});

it('una fila con carrera, plan de esa carrera y campus que existen no trae errores', function () {
    $issues = $this->template->validate(studentRow(), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('revisa contra la base la carrera, el plan y el campus', function (array $changes, string $field, string $message) {
    StudyPlan::factory()->create(['code' => 'IIND-2010-227']);

    expect($this->template->validate(studentRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'carrera que no existe' => [['career' => 'XYZ'], 'career', 'No hay una carrera con la clave XYZ.'],
    'plan que no existe' => [['study_plan' => 'P-1'], 'study_plan', 'No hay un plan con la clave P-1.'],
    'plan de otra carrera' => [['study_plan' => 'IIND-2010-227'], 'study_plan', 'El plan IIND-2010-227 no es de la carrera ISIC.'],
    'campus que no existe' => [['campus' => 'C9'], 'campus', 'No hay un campus con la clave C9.'],
]);

it('un estatus que Nexus no conoce es advertencia: el alumno queda sin estatus, también si ya tenía uno', function (bool $existing) {
    if ($existing) {
        $this->template->apply(studentRow(), $this->context);
    }
    $row = studentRow(['status' => 'SUSPENDIDO']);

    $issues = $this->template->validate($row, $this->context);
    $student = $this->template->apply($row, $this->context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toBe(['status' => ['El estatus «SUSPENDIDO» no está en Nexus: el alumno queda sin estatus.']])
        ->and($student->fresh()->status)->toBeNull()
        ->and(Student::count())->toBe(1);
})->with(['alta' => false, 'cambio' => true]);

it('no ofrece desactivar faltantes: un archivo suele ser de un campus y el número de control es de todo el Tec', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});

it('busca cada carrera, plan y campus una sola vez por corrida', function () {
    DB::enableQueryLog();

    foreach (['19111234', '19111235', '19111236'] as $number) {
        $row = studentRow(['control_number' => $number]);
        $this->template->validate($row, $this->context);
        $this->template->apply($row, $this->context);
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "(careers|study_plans|campuses)"/', $sql) === 1);

    expect($lookups)->toHaveCount(3);
});

it('da de alta y actualiza por número de control, con la carrera, el plan y el campus de la base', function () {
    $created = $this->template->apply(studentRow(), $this->context);
    $updated = $this->template->apply(studentRow(['name' => 'Ana María López', 'semester' => 4, 'status' => 'baja temporal']), $this->context);

    expect(Student::count())->toBe(1)
        ->and($updated->is($created))->toBeTrue()
        ->and($updated->fresh())
        ->name->toBe('Ana María López')
        ->career_id->toBe($this->career->id)
        ->study_plan_id->toBe($this->plan->id)
        ->campus_id->toBe($this->c2->id)
        ->current_semester->toBe(4)
        ->status->toBe(StudentStatus::BajaTemporal);
});

it('al terminar el lote despacha el recálculo del progreso', function () {
    Queue::fake();

    $this->template->afterApply($this->context);

    Queue::assertPushed(RecomputeStudentProgress::class);
});
