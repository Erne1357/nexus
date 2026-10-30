<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TeachersTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `teachers` (tarea 10): los docentes del SII con su adscripción. Un renglón por docente,
| departamento y campus (S5); el que el SII no marca activo nace inactivo y uno activo no se desactiva (S4);
| un grado desconocido queda vacío con advertencia (S6). No ofrece «desactivar faltantes».
*/

beforeEach(function () {
    $this->sistemas = Department::factory()->create(['code' => 'SC']);
    $this->industrial = Department::factory()->create(['code' => 'II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new TeachersTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function teacherRow(array $changes = []): array
{
    return (new TeachersTemplate)->normalize([
        'employee_number' => '1001', 'name' => 'Ana López', 'department' => 'sc', 'campus' => 'c2',
        'email' => 'ana@example.test', 'degree' => 'Maestría', 'active' => 'Sí', 'hired_on' => '05/08/2015',
        ...$changes,
    ]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('teachers'))
        ->toBeInstanceOf(TeachersTemplate::class)
        ->label()->toBe('Docentes');
});

it('una fila completa no trae errores ni advertencias', function () {
    $issues = $this->template->validate(teacherRow(), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('revisa contra la base el departamento y el campus', function (array $changes, string $field, string $message) {
    expect($this->template->validate(teacherRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'departamento que no existe' => [['department' => 'XX'], 'department', 'No hay un departamento con la clave XX.'],
    'campus que no existe' => [['campus' => 'C9'], 'campus', 'No hay un campus con la clave C9.'],
]);

it('rechaza un correo, una fecha o un activo que no se entienden', function (array $changes, string $field) {
    expect($this->template->validate(teacherRow($changes), $this->context)->errors)->toHaveKey($field);
})->with([
    'correo' => [['email' => 'no es correo'], 'email'],
    'fecha imposible' => [['hired_on' => '31/02/2015'], 'hired_on'],
    'activo raro' => [['active' => 'tal vez'], 'active'],
    'sin nombre' => [['name' => null], 'name'],
]);

it('da de alta al docente con sus datos y su adscripción principal', function () {
    $teacher = $this->template->apply(teacherRow(), $this->context)->fresh();

    expect($teacher)->employee_number->toBe('1001')->name->toBe('Ana López')->email->toBe('ana@example.test')
        ->degree->toBe(TeacherDegree::Maestria)->is_active->toBeTrue()
        ->and($teacher->hired_on->toDateString())->toBe('2015-08-05')
        ->and($teacher->adscriptions)->toHaveCount(1)
        ->and($teacher->adscriptions->first())->department_id->toBe($this->sistemas->id)->campus_id->toBe($this->c2->id)->is_primary->toBeTrue();
});

it('un número de empleado con espacios se importa igual que uno capturado', function () {
    $teacher = $this->template->apply(teacherRow(['employee_number' => ' 10 01 ']), $this->context);

    expect($teacher->employee_number)->toBe('1001');
});

it('S6: un grado desconocido queda vacío con advertencia, también en un cambio', function () {
    $issues = $this->template->validate(teacherRow(['degree' => 'Posdoctorado']), $this->context);
    $created = $this->template->apply(teacherRow(['degree' => 'Posdoctorado']), $this->context);
    Teacher::query()->update(['degree' => 'doctorado']);
    $changed = $this->template->apply(teacherRow(['degree' => 'Posdoctorado']), $this->context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toHaveKey('degree')
        ->and($created->fresh()->degree)->toBeNull()
        ->and($changed->fresh()->degree)->toBeNull();
});

it('S4: el docente que el SII no marca activo nace inactivo', function (mixed $active) {
    $teacher = $this->template->apply(teacherRow(['active' => $active]), $this->context);

    expect($teacher->fresh()->is_active)->toBeFalse();
})->with(['No', 'Inactivo', '0']);

it('S4: un docente sin dato de activo nace activo', function () {
    expect($this->template->apply(teacherRow(['active' => null]), $this->context)->fresh()->is_active)->toBeTrue();
});

it('S4: uno activo que el archivo marca inactivo sigue activo, con advertencia', function () {
    Teacher::factory()->create(['employee_number' => '1001']);

    $issues = $this->template->validate(teacherRow(['active' => 'No']), $this->context);
    $teacher = $this->template->apply(teacherRow(['active' => 'No']), $this->context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toHaveKey('active')
        ->and($teacher->fresh()->is_active)->toBeTrue();
});

it('un docente inactivo se actualiza y sigue inactivo, con advertencia; uno borrado es error', function () {
    $inactive = Teacher::factory()->inactive()->create(['employee_number' => '1001']);
    Teacher::factory()->create(['employee_number' => '1002'])->delete();

    $warning = $this->template->validate(teacherRow(['name' => 'Ana Nueva']), $this->context);
    $this->template->apply(teacherRow(['name' => 'Ana Nueva']), $this->context);

    expect($warning->errors)->toBe([])
        ->and($warning->warnings)->toHaveKey('employee_number')
        ->and($inactive->fresh())->name->toBe('Ana Nueva')->is_active->toBeFalse()
        ->and($this->template->validate(teacherRow(['employee_number' => '1002']), $this->context)->errors)->toHaveKey('employee_number');
});

it('S5: dos renglones del mismo docente con otro departamento o campus son adscripciones, no repetidos; la primera es la principal', function () {
    $first = teacherRow();
    $second = teacherRow(['department' => 'II']);
    $third = teacherRow(['campus' => 'C1']);

    foreach ([$first, $second, $third] as $row) {
        $this->template->apply($row, $this->context);
    }

    $adscriptions = Teacher::sole()->adscriptions()->orderBy('created_at')->get();

    expect(Teacher::count())->toBe(1)
        ->and($adscriptions)->toHaveCount(3)
        ->and($adscriptions->pluck('is_primary')->all())->toBe([true, false, false])
        ->and($this->template->keyFor($first, $this->context))->not->toBe($this->template->keyFor($second, $this->context))
        ->and($this->template->keyFor($first, $this->context))->not->toBe($this->template->keyFor($third, $this->context));
});

it('S5: si el docente ya tiene otra principal, la conserva y avisa', function () {
    $teacher = Teacher::factory()->adscribedTo($this->industrial, $this->c1)->create(['employee_number' => '1001']);

    $issues = $this->template->validate(teacherRow(), $this->context);
    $this->template->apply(teacherRow(), $this->context);

    $primary = $teacher->adscriptions()->where('is_primary', true)->sole();

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toHaveKey('department')
        ->and($teacher->adscriptions()->count())->toBe(2)
        ->and($primary->department_id)->toBe($this->industrial->id);
});

it('una adscripción borrada a mano es error: importar no la restaura', function () {
    $teacher = Teacher::factory()->create(['employee_number' => '1001']);
    TeacherDepartment::factory()->for($teacher)->for($this->sistemas)->for($this->c2)->create()->delete();

    expect($this->template->validate(teacherRow(), $this->context)->errors)->toHaveKey('department');
});

it('aplicar dos veces la misma fila no cambia nada', function () {
    $this->template->apply(teacherRow(), $this->context);
    $teacher = $this->template->apply(teacherRow(), $this->context);

    expect($teacher->wasRecentlyCreated)->toBeFalse()
        ->and($teacher->wasChanged())->toBeFalse()
        ->and($this->template->wroteAlongside($teacher))->toBeFalse()
        ->and(Teacher::count())->toBe(1)
        ->and(TeacherDepartment::count())->toBe(1);
});

it('un renglón que solo agrega una adscripción cuenta como cambio del docente; repetirlo, no', function () {
    $this->template->apply(teacherRow(), $this->context);

    $teacher = $this->template->apply(teacherRow(['department' => 'II']), $this->context);
    $alongside = $this->template->wroteAlongside($teacher);
    $again = $this->template->apply(teacherRow(['department' => 'II']), $this->context);

    expect($alongside)->toBeTrue()->and($this->template->wroteAlongside($again))->toBeFalse();
});

it('no pisa con vacío lo que el archivo no trae', function () {
    $this->template->apply(teacherRow(), $this->context);
    $teacher = $this->template->apply(teacherRow(['email' => null, 'degree' => null, 'hired_on' => null]), $this->context)->fresh();

    expect($teacher)->email->toBe('ana@example.test')->degree->toBe(TeacherDegree::Maestria)
        ->and($teacher->hired_on->toDateString())->toBe('2015-08-05');
});

it('no ofrece desactivar faltantes: es global y la baja desactiva usuarios (S4)', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});

it('al aplicar despacha una vez el alta de usuarios', function () {
    Queue::fake();

    $this->template->afterApply($this->context);

    Queue::assertPushed(ProvisionTeacherUsersJob::class, 1);
});

it('en un archivo, el nombre de un docente debe coincidir en sus renglones: avisa y se queda el del primero', function () {
    Storage::fake('imports');
    Queue::fake();
    $batch = ImportBatch::factory()->create(['template' => 'teachers', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", [
        'número de empleado,nombre,departamento,campus,correo,grado,activo,fecha de ingreso',
        '1001,Ana López,SC,C2,ana@example.test,Maestría,Sí,05/08/2015',
        '1001,Ana López,II,C2,ana@example.test,Maestría,Sí,05/08/2015',
        '1001,Ana Lopez R.,II,C1,ana@example.test,Maestría,Sí,05/08/2015',
        '1001,Ana López,SC,C2,ana@example.test,Maestría,Sí,05/08/2015',
    ]));

    app(ValidateImportBatch::class)->handle($batch);
    $rows = $batch->rows()->orderBy('row_number')->get()->keyBy('row_number');

    expect($rows[2]->status)->toBe(RowStatus::Valid)
        ->and($rows[3]->status)->toBe(RowStatus::Valid)
        ->and($rows[4])->status->toBe(RowStatus::Warning)->warnings->toHaveKey('name')
        ->and($rows[5]->status)->toBe(RowStatus::Error);

    app(ApplyImportBatch::class)->handle($batch->fresh(), $this->context->user);

    expect(Teacher::sole()->name)->toBe('Ana López')
        ->and(TeacherDepartment::count())->toBe(3);
    Queue::assertPushed(ProvisionTeacherUsersJob::class);
});

it('el archivo de ejemplo anonimizado se valida y se aplica: S4, S5 y S6 en un archivo', function () {
    Storage::fake('imports');
    Queue::fake();
    $batch = ImportBatch::factory()->create(['template' => 'teachers', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, file_get_contents(base_path('tests/Fixtures/imports/teachers.csv')));

    $batch = app(ValidateImportBatch::class)->handle($batch);
    $batch = app(ApplyImportBatch::class)->handle($batch->fresh(), $this->context->user);

    expect([$batch->error_rows, $batch->created_rows])->toBe([0, 3])
        ->and(Teacher::where('employee_number', '90003')->sole())->is_active->toBeFalse()->degree->toBeNull()
        ->and(Teacher::where('employee_number', '90001')->sole()->adscriptions()->where('is_primary', true)->sole()->department_id)->toBe($this->sistemas->id);
});
