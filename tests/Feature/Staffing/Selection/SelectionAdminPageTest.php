<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| Página de admin «Orden de selección» (tarea 1.33, diseño 6.8): el admin prepara la selección en siete pasos y,
| ya publicada, sigue el avance en un monitor. Solo con `staffing.selection.manage`.
*/

const ADMIN_HEADER = "Nombre completo;horas;auto ev;cumpl asig;C1;C2;antiguedad\n";

/**
 * @return array{term: Term, modality: Modality, department: Department, load: AcademicLoad, subject: Subject}
 */
function adminScope(): array
{
    $term = Term::factory()->create(['is_current' => true]);
    $modality = Modality::factory()->create();
    $department = Department::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Draft)->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id]);
    $subject = Subject::factory()->create(['department_id' => $department->id, 'required_space' => null]);
    Group::factory()->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id, 'subject_id' => $subject->id]);

    return compact('term', 'modality', 'department', 'load', 'subject');
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('el admin sube el CSV y resuelve un renglón sin empatar', function () {
    $s = adminScope();
    $ana = Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Ana Pérez Lara']);
    $luis = Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Luis Gómez Ruiz']);
    $csv = ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\nNombre Desconocido;20;si;no;si;si;3\n";

    $page = Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->assertSet('step', 1)
        ->call('next')
        ->assertSet('step', 2)
        ->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', $csv))
        ->call('importCsv')
        ->assertSee('Sin coincidencia')
        ->assertSee('Falta 1 fila por resolver.');

    $selection = Selection::query()->firstOrFail();
    $entry = $selection->entries()->whereNull('teacher_id')->firstOrFail();

    $page->call('resolve', $entry->id, $luis->id)
        ->assertDontSee('Falta 1 fila por resolver.');

    expect($entry->fresh()->teacher_id)->toBe($luis->id)
        ->and($selection->entries()->whereNull('teacher_id')->count())->toBe(0);
});

it('publica con todo listo', function () {
    Notification::fake();
    $s = adminScope();
    $teacher = Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Ana Pérez Lara']);

    $page = Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->call('goTo', 2)
        ->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\n"))
        ->call('importCsv')
        ->call('setSpace', $s['subject']->id, 'aula')
        ->set('startsOn', now()->addDays(3)->format('Y-m-d'))
        ->set('startsAt', '13:00')
        ->call('schedule')
        ->call('goTo', 7)
        ->call('publish')
        ->assertHasNoErrors();

    $selection = Selection::query()->firstOrFail();
    expect($selection->status)->toBe(SelectionStatus::Published)
        ->and($s['subject']->fresh()->required_space->value)->toBe('aula')
        ->and($selection->entries()->where('teacher_id', $teacher->id)->exists())->toBeTrue();
    $page->assertSee('Terminaron');
});

it('no publica con una materia sin revisar y dice por qué', function () {
    $s = adminScope();
    Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Ana Pérez Lara']);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->call('goTo', 2)
        ->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\n"))
        ->call('importCsv')
        ->set('startsOn', now()->addDays(3)->format('Y-m-d'))
        ->call('schedule')
        ->call('publish')
        ->assertHasErrors('publish');

    expect(Selection::query()->firstOrFail()->status)->toBe(SelectionStatus::Draft);
});

it('el monitor muestra el turno y el avance de una selección publicada', function () {
    $selection = Selection::factory()->create(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);
    $teacher = Teacher::factory()->create(['name' => 'Rosa Medina Soto']);
    SelectionEntry::factory()->for($selection)->create([
        'teacher_id' => $teacher->id, 'position' => 1, 'slot_index' => 0,
        'slot_starts_at' => now()->subMinutes(5), 'slot_ends_at' => now()->addMinutes(25),
    ]);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => $selection->id])
        ->assertSee('Rosa Medina Soto')
        ->assertSee('Ahora')
        ->assertSeeHtml('wire:poll.10s');
});

it('un jefe de departamento sin SelectionManage recibe 403', function () {
    $s = adminScope();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $s['department']->id])->create();

    $this->actingAs($jefe)->get(route('staffing.selection.admin'))->assertForbidden();
});

it('un docente recibe 403', function () {
    $docente = User::factory()->withRole(Role::Docente)->create();

    $this->actingAs($docente)->get(route('staffing.selection.admin'))->assertForbidden();
});

it('el admin entra a la página', function () {
    adminScope();

    $this->actingAs($this->admin)->get(route('staffing.selection.admin'))->assertOk()->assertSee('Orden de selección');
});

it('quita una carga incluida del borrador', function () {
    $s = adminScope();

    $page = Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start');

    $selection = Selection::query()->firstOrFail();
    expect($selection->loads()->count())->toBe(1);

    $page->call('removeLoad', $s['load']->id);

    expect($selection->loads()->count())->toBe(0);
});
