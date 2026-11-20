<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/*
| R-V2-4 (confirmado el 19 de noviembre de 2026): el jefe de una unidad no académica solo lleva el título de su
| unidad. Cada permiso del jefe declara las unidades a las que aplica (AZ-02), así que no recibe nada académico.
*/

beforeEach(function () {
    $this->hr = OrgUnit::factory()->create(['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento]);
    $this->division = OrgUnit::factory()->create(['code' => 'DIV-PROFESIONAL', 'name' => 'División de Estudios Profesionales', 'kind' => OrgUnitKind::Division]);
    $this->office = OrgUnit::factory()->create(['code' => 'COORDINACION-CAMPUS-II', 'name' => 'Coordinación General Campus II', 'kind' => OrgUnitKind::Oficina]);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);
});

it('el jefe de una unidad no académica no ve nada académico', function (string $unit) {
    $head = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->{$unit}->id])->create(['name' => 'Hugo Jefe']);
    $plan = StudyPlan::factory()->for($this->isc)->create();

    foreach (['academic-load.inbox', 'academic-load.proposal', 'staffing.assign', 'staffing.teacher-loads', 'catalog-validation.review', 'faculty.teachers', 'students.index', 'academic.subjects'] as $route) {
        $this->actingAs($head)->get(route($route))->assertForbidden();
    }

    $this->actingAs($head)->get(route('academic.plans.show', $plan))->assertForbidden();
})->with(['hr', 'division', 'office']);

it('el jefe de una unidad no académica ve su título y que su área todavía no tiene funciones', function (string $unit, string $title) {
    $head = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->{$unit}->id])->create();
    $this->actingAs($head);

    Livewire::test('core::pages.home')
        ->assertSeeInOrder([$title, 'Tu área todavía no tiene funciones en Nexus.'])
        ->assertDontSee('Validas los catálogos');
})->with([
    ['hr', 'Jefe del Departamento de Recursos Humanos'],
    ['division', 'Jefe de la División de Estudios Profesionales'],
    ['office', 'Titular de la Coordinación General Campus II'],
]);
