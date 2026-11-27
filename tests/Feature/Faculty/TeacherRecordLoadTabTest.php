<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Pestaña «Carga» de la ficha del docente (D-03, tarea 1.33): la semana del periodo en curso (del SII si Nexus no tiene
| grupos en él) y la del siguiente periodo cuando ya tiene grupos en Nexus, con salón y horas frente a grupo con su banda (PL-01, HC-08). La ve
| quien ve la ficha (TeacherPolicy).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'America/Ciudad_Juarez'));
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->next = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->dept = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create(['rfc' => 'AAAA800101AA1', 'name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->dept->id, 'code' => 'AED-1026', 'name' => 'Estructura de Datos', 'theory_hours' => 4, 'practice_hours' => 0]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->for($this->campus)->create()->id, 'code' => '701']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->dept->id])->create();
});

function loadTabSiiGroup(): void
{
    $group = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20263', 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id, 'group_code' => '3A']);
    DB::table('historical_group_sessions')->insert([
        'historical_group_id' => $group->id, 'day' => 1, 'starts_at' => '11:00', 'ends_at' => '13:00', 'room_code' => '701',
        'teacher_rfc' => 'AAAA800101AA1', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function loadTabNexusGroup(): void
{
    $group = Group::factory()->create(['term_id' => test()->next->id, 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id, 'code' => 'C', 'teacher_id' => test()->teacher->id]);
    GroupSession::factory()->on(2, '09:00', '11:00')->create(['group_id' => $group->id, 'room_id' => test()->room->id]);
}

it('la ficha muestra la carga del semestre en curso desde el SII con salón y horas contra la base', function () {
    loadTabSiiGroup();

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSee('Ago-Dic 2026')
        ->assertSee('En curso')
        ->assertSee('Del SII')
        ->assertSee('AED-1026')
        ->assertSee('701')
        ->assertSeeHtml('data-testid="load-hours-current"')
        ->assertSee('Frente a grupo: 22 h (20 a 24)')
        ->assertSee('Dentro de su banda')
        ->assertDontSee('h de base')
        ->assertSee('Todavía no tiene grupos de Ene-Jun 2027');
});

it('la ficha muestra la carga del siguiente semestre cuando tiene grupos en Nexus', function () {
    loadTabSiiGroup();
    loadTabNexusGroup();

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSee('Ago-Dic 2026')
        ->assertSee('Ene-Jun 2027')
        ->assertSee('De la selección')
        ->assertSeeHtml('data-testid="load-hours-next"')
        ->assertDontSee('Todavía no tiene grupos');
});

it('PL-03: con plaza administrativa la ficha dice «Plaza administrativa: 36 h · clases 4 h · 40 de 42 h», sin banda ni «Pasa su banda» (confirmado el 27 de noviembre de 2026)', function () {
    TeacherPosition::query()->where('teacher_id', $this->teacher->id)->delete();
    TeacherPosition::factory()->for($this->teacher)->create(['kind' => PositionKind::Apoyo, 'base_hours' => 36, 'category' => 'IA08']);
    loadTabSiiGroup();

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSeeHtml('data-testid="load-band-current"')
        ->assertSee('Plaza administrativa: 36 h · clases 4 h · 40 de 42 h')
        ->assertDontSee('Frente a grupo')
        ->assertDontSee('Pasa su banda')
        ->assertDontSee('oficio');
});

it('quien no puede ver la ficha no ve la pestaña Carga', function () {
    $otro = Department::factory()->create(['code' => 'CB']);
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $otro->id])->create();

    Livewire::actingAs($jefeOtro)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertForbidden();

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
        ->assertSeeHtml('data-testid="tab-carga"');
});

/*
| Carga en su departamento y en los demás (tarea 1.59, B3): con grupos en varios departamentos (el jefe pudo asignarlo
| sin pedir prestado, 1.58), la pestaña parte la semana por departamento y colorea la cuadrícula.
*/

function loadTabGroupIn(Department $department, string $subjectCode, string $name, int $day, string $from, string $to): void
{
    $subject = Subject::factory()->create(['department_id' => $department->id, 'code' => $subjectCode, 'name' => $name, 'theory_hours' => 2, 'practice_hours' => 0]);
    $group = Group::factory()->create(['term_id' => test()->next->id, 'campus_id' => test()->campus->id, 'subject_id' => $subject->id, 'department_id' => $department->id, 'code' => 'A', 'teacher_id' => test()->teacher->id]);
    GroupSession::factory()->on($day, $from, $to)->create(['group_id' => $group->id, 'room_id' => test()->room->id]);
}

it('la ficha parte la carga en su departamento y en otros departamentos, con el jefe de cada uno y la leyenda', function () {
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create(['name' => 'Héctor Olivas']);
    loadTabGroupIn($this->dept, 'AED-2000', 'Bases de Datos', 1, '10:00', '12:00');
    loadTabGroupIn($cb, 'ACF-0901', 'Cálculo Diferencial', 2, '08:00', '10:00');

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSeeHtml('data-testid="load-departments-next"')
        ->assertSee('En Sistemas y Computación')
        ->assertSee('Su departamento')
        ->assertSee('En otros departamentos · 2 h')
        ->assertSee('Ciencias Básicas')
        ->assertSee('Otro departamento')
        ->assertSee('Jefe: Héctor Olivas')
        ->assertSee('Cálculo Diferencial')
        ->assertSeeHtml('data-testid="load-legend-next"')
        ->assertSee('Frente a grupo: 22 h (20 a 24)');
});

it('PL-02: una tutoría sale en la semana y en su departamento con «0 h · no cuenta» y no suma en sus horas', function () {
    loadTabGroupIn($this->dept, 'AED-2000', 'Bases de Datos', 1, '10:00', '12:00');
    loadTabGroupIn($this->dept, 'TUT-2024', 'Tutorías', 3, '10:00', '11:00');
    Subject::query()->where('code', 'TUT-2024')->update(['counts_teacher_hours' => false]);

    $html = Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSee('Tutorías')
        ->assertSeeHtml('data-testid="load-no-teacher-hours"')
        ->html();

    expect(trim(strip_tags(domOf($html)->querySelector('[data-testid=load-hours-next] .font-mono')?->textContent ?? '')))->toBe('2');
});

it('un departamento sin jefe vigente lo dice', function () {
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    loadTabGroupIn($cb, 'ACF-0901', 'Cálculo Diferencial', 2, '08:00', '10:00');

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSee('Sin jefe vigente');
});

it('la carga del SII sale sin partir por departamento', function () {
    loadTabSiiGroup();

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertDontSeeHtml('data-testid="load-departments-current"')
        ->assertSeeHtml('data-testid="week-grid"');
});

it('un jefe de otro departamento sigue sin abrir la ficha de un docente fuera de su alcance', function () {
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    loadTabGroupIn($cb, 'ACF-0901', 'Cálculo Diferencial', 2, '08:00', '10:00');
    $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create();
    $outsider = Teacher::factory()->adscribedTo(Department::factory()->create(['code' => 'II']))->create();

    $this->actingAs($other)->get(route('faculty.teachers.show', $outsider->id))->assertForbidden();
});

it('PL-01 y HC-08: la categoría de la plaza cambia las horas frente a grupo de la pestaña y, si pasa la banda, muestra el aviso', function () {
    TeacherPosition::query()->where('teacher_id', $this->teacher->id)->update(['category' => 'E3809']);
    $subject = Subject::factory()->create(['department_id' => $this->dept->id, 'theory_hours' => 30, 'practice_hours' => 0]);
    Group::factory()->create(['term_id' => $this->next->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'code' => 'Z', 'teacher_id' => $this->teacher->id]);

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id, 'tab' => 'carga'])
        ->assertSee('Frente a grupo: 24 h (22 a 26)')
        ->assertSee('Con horas extra')
        ->assertSeeHtml('data-testid="load-notice-next"')
        ->assertSee('Pasa su banda de horas frente a grupo (24 h, de 22 a 26): lleva 30 h.');
});
