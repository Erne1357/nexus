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
