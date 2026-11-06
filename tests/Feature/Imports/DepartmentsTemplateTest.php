<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\DepartmentsTemplate;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `departments` (tarea 1.9): los departamentos del organigrama por clave y nombre. Se importan primero,
| porque las carreras y las materias del SII traen la clave de su departamento (confirmado el 29 de octubre de
| 2026). No ofrece "desactivar faltantes".
*/

beforeEach(function () {
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new DepartmentsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function departmentRow(array $changes = []): array
{
    return (new DepartmentsTemplate)->normalize(['code' => 'sc', 'name' => 'Sistemas y Computación', ...$changes]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('departments'))
        ->toBeInstanceOf(DepartmentsTemplate::class)
        ->label()->toBe('Departamentos');
});

it('pide clave y nombre, y pone la clave en mayúsculas', function () {
    expect(departmentRow()['code'])->toBe('SC')
        ->and($this->template->validate(departmentRow(['name' => null]), $this->context)->errors)->toHaveKey('name');
});

it('da de alta y actualiza por clave, sin duplicar', function () {
    $created = $this->template->apply(departmentRow(), $this->context);
    $updated = $this->template->apply(departmentRow(['name' => 'Sistemas Computacionales']), $this->context);

    expect(Department::count())->toBe(1)
        ->and($updated->is($created))->toBeTrue()
        ->and($updated->fresh())->code->toBe('SC')->name->toBe('Sistemas Computacionales')->is_active->toBeTrue();
});

it('no ofrece desactivar faltantes: la exportación puede no traer el organigrama completo', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});

it('un alta con activo No nace inactiva y sin activo nace activa', function () {
    $inactive = $this->template->apply(departmentRow(['code' => 'OLD', 'active' => 'No']), $this->context);
    $active = $this->template->apply(departmentRow(['code' => 'NEW', 'active' => 'Sí']), $this->context);
    $unspecified = $this->template->apply(departmentRow(['code' => 'ANY']), $this->context);

    expect($inactive->fresh()->is_active)->toBeFalse()
        ->and($active->fresh()->is_active)->toBeTrue()
        ->and($unspecified->fresh()->is_active)->toBeTrue();
});

it('un cambio nunca reactiva ni desactiva: avisa', function () {
    $this->template->apply(departmentRow(['active' => 'No']), $this->context);

    $inactiveIssues = $this->template->validate(departmentRow(['active' => 'Sí']), $this->context);
    $this->template->apply(departmentRow(['active' => 'Sí']), $this->context);
    $stillInactive = Department::query()->where('code', 'SC')->sole()->is_active;

    $this->template->apply(departmentRow(['code' => 'ACT']), $this->context);
    $activeIssues = $this->template->validate(departmentRow(['code' => 'ACT', 'active' => 'No']), $this->context);
    $this->template->apply(departmentRow(['code' => 'ACT', 'active' => 'No']), $this->context);

    expect($stillInactive)->toBeFalse()
        ->and($inactiveIssues->warnings)->toHaveKey('code')
        ->and($activeIssues->warnings)->toHaveKey('active')
        ->and(Department::query()->where('code', 'ACT')->sole()->is_active)->toBeTrue();
});

it('rechaza un valor de activo que no entiende', function () {
    expect($this->template->validate(departmentRow(['active' => 'quizá']), $this->context)->errors)->toHaveKey('active');
});
