<?php

use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| PL-02 (tarea 1.73): si las horas de una materia cuentan en la carga del docente lo marcan el jefe de su departamento y
| el admin desde el catálogo de Materias, con el permiso del tipo de espacio (`academic.subject.set_space`, alcance: el
| departamento de la materia), con bitácora.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ele = Department::factory()->create(['code' => 'ELE']);
    $this->tutoring = Subject::factory()->create(['code' => 'TUT-2024', 'name' => 'Tutorías', 'department_id' => $this->sis->id, 'theory_hours' => 1, 'practice_hours' => 0]);
    $this->circuits = Subject::factory()->create(['code' => 'AEF-1002', 'department_id' => $this->ele->id]);

    $this->jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->jefeEle = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();
});

it('PL-02: una materia nueva cuenta en la carga del docente por omisión', function () {
    expect(Subject::factory()->create()->fresh()->counts_teacher_hours)->toBeTrue();
});

it('PL-02: el jefe marca que una materia de su departamento no cuenta en la carga del docente, con bitácora y aviso', function () {
    Event::fake([SubjectUpdated::class]);
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->assertSeeHtml('aria-label="Carga del docente de TUT-2024"')
        ->call('editTeacherHours', $this->tutoring->id)
        ->assertSet('countsTeacherHours', true)
        ->set('countsTeacherHours', false)
        ->call('saveTeacherHours')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-testid="no-teacher-hours-TUT-2024"');

    $entry = Activity::query()->where('subject_id', $this->tutoring->id)->where('causer_id', $this->jefeSis->id)->sole();

    expect($this->tutoring->fresh())->counts_teacher_hours->toBeFalse()->teacher_hours->toBe(0)
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['counts_teacher_hours' => false], 'old' => ['counts_teacher_hours' => true]]);
    Event::assertDispatched(SubjectUpdated::class, fn (SubjectUpdated $event): bool => $event->subject->is($this->tutoring) && $event->changed === ['counts_teacher_hours']);
});

it('PL-02: guardar sin cambio no escribe ni deja bitácora', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->call('editTeacherHours', $this->tutoring->id)->call('saveTeacherHours');

    expect(Activity::query()->where('subject_id', $this->tutoring->id)->exists())->toBeFalse();
});

it('PL-02: el admin la marca en cualquier departamento desde «Acciones»', function () {
    $admin = User::factory()->admin()->create();
    actingAsRole($admin, Role::Admin);

    Livewire::test('academic::pages.subjects')
        ->call('editTeacherHours', $this->circuits->id)
        ->set('countsTeacherHours', false)
        ->call('saveTeacherHours');

    expect($this->circuits->fresh()->counts_teacher_hours)->toBeFalse();
});

it('PL-02: el jefe de otro departamento no ve la materia ni puede marcarla', function () {
    actingAsRole($this->jefeEle, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->assertDontSee('TUT-2024');
    Livewire::test('academic::pages.subjects')->call('editTeacherHours', $this->tutoring->id)->assertForbidden();

    expect($this->tutoring->fresh()->counts_teacher_hours)->toBeTrue();
});

it('PL-02: una página abierta por el admin no deja guardar al jefe de otro departamento', function () {
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('academic::pages.subjects')
        ->call('editTeacherHours', $this->tutoring->id)
        ->set('countsTeacherHours', false);

    actingAsRole($this->jefeEle, Role::JefeDepartamento);
    $page->call('saveTeacherHours')->assertForbidden();

    expect($this->tutoring->fresh()->counts_teacher_hours)->toBeTrue();
});

it('PL-02: el jefe de proyecto docente y el docente no la marcan', function (Role $role) {
    $user = User::factory()->withRole($role, ['department_id' => $this->sis->id])->create();

    expect($user->can('setTeacherHours', $this->tutoring))->toBeFalse();
})->with([Role::JefeProyectoDocente, Role::Docente]);
