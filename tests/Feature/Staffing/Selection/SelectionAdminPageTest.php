<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Actions\GrantPositionPermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Notifications\SelectionCancelled;
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

it('PL-01: el monitor muestra las horas frente a grupo, igual que el docente, y no las 40 h del CSV', function () {
    $selection = Selection::factory()->create(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);
    $teacher = Teacher::factory()->create(['name' => 'Rosa Medina Soto']);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create(['category' => 'E3817']);
    SelectionEntry::factory()->for($selection)->create([
        'teacher_id' => $teacher->id, 'base_hours' => 40, 'position' => 1, 'slot_index' => 0,
        'slot_starts_at' => now()->subMinutes(5), 'slot_ends_at' => now()->addMinutes(25),
    ]);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => $selection->id])
        ->assertSee('frente a grupo: 22 h (20 a 24)')
        ->assertDontSee('de 40 h');
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

it('no se puede saltar al paso 3 con renglones sin resolver', function () {
    $s = adminScope();
    Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Ana Pérez Lara']);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->call('goTo', 2)
        ->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER."Nombre Desconocido;20;si;no;si;si;3\n"))
        ->call('importCsv')
        ->call('goTo', 3)
        ->assertSet('step', 2)
        ->call('goTo', 1)
        ->assertSet('step', 1);
});

it('un seleccion desconocido en la URL arranca de cero', function () {
    adminScope();

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => 'no-es-un-uuid'])
        ->assertSet('selectionId', '')
        ->assertSet('step', 1)
        ->call('goTo', 3)
        ->assertSet('step', 1);
});

it('SEL-11: el admin cancela desde el monitor una selección publicada que aún no empieza, con un modal, y se avisa a la cola', function () {
    Notification::fake();
    $selection = Selection::factory()->create(['status' => SelectionStatus::Published, 'starts_at' => now()->addDay(), 'published_at' => now()]);
    $user = User::factory()->create();
    SelectionEntry::factory()->for($selection)->create([
        'teacher_id' => Teacher::factory()->create(['name' => 'Rosa Medina Soto', 'user_id' => $user->id])->id, 'position' => 1, 'slot_index' => 0,
        'slot_starts_at' => now()->addDay(), 'slot_ends_at' => now()->addDay()->addMinutes(30),
    ]);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => $selection->id])
        ->assertSet('tab', 'monitor')
        ->assertSeeHtml('data-testid="monitor-cancel"')
        ->call('$set', 'showCancel', true)
        ->assertSee('Los docentes de la cola reciben el aviso')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('selectionId', '');

    expect($selection->fresh()->status)->toBe(SelectionStatus::Cancelled);
    Notification::assertSentTo($user, SelectionCancelled::class);
});

it('el monitor no ofrece cancelar una selección que ya empezó', function () {
    $selection = Selection::factory()->create(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => $selection->id])
        ->assertSee('Avance por docente')
        ->assertDontSeeHtml('data-testid="monitor-cancel"');
});

/**
 * Un jefe del departamento de `$scope` al que el admin le dio `staffing.selection.manage` (los puestos se editan en 1.22):
 * maneja solo las selecciones de su departamento.
 *
 * @param  array{department: Department}  $scope
 */
function scopedSelectionManager(array $scope): User
{
    app(GrantPositionPermission::class)->handle(Role::JefeDepartamento, StaffingPermission::SelectionManage);

    return User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $scope['department']->id])->create();
}

it('start() revisa el permiso sobre el departamento antes de crear el borrador', function () {
    $own = adminScope();
    $other = Department::factory()->create();
    $manager = scopedSelectionManager($own);

    Livewire::actingAs($manager)->test('staffing::pages.selection-admin')
        ->set('termId', $own['term']->id)
        ->set('modalityId', $own['modality']->id)
        ->set('departmentId', $other->id)
        ->call('start')
        ->assertForbidden();

    expect(Selection::query()->count())->toBe(0);

    Livewire::actingAs($manager)->test('staffing::pages.selection-admin')
        ->set('termId', $own['term']->id)
        ->set('modalityId', $own['modality']->id)
        ->set('departmentId', $own['department']->id)
        ->call('start')
        ->assertHasNoErrors();

    expect(Selection::query()->sole()->department_id)->toBe($own['department']->id);
});

it('una selección de otro departamento puesta a mano en `selectionId` da 403 y no se muestra', function () {
    $own = adminScope();
    $manager = scopedSelectionManager($own);
    $foreign = Selection::factory()->create(['term_id' => $own['term']->id, 'modality_id' => $own['modality']->id, 'department_id' => Department::factory()->create()->id, 'status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);
    SelectionEntry::factory()->for($foreign)->create(['teacher_id' => Teacher::factory()->create(['name' => 'Docente Ajeno Ficticio'])->id, 'position' => 1]);

    Livewire::actingAs($manager)->test('staffing::pages.selection-admin')
        ->set('selectionId', $foreign->id)
        ->assertForbidden();

    Livewire::actingAs($manager)->test('staffing::pages.selection-admin', ['seleccion' => $foreign->id])
        ->assertForbidden();
});

it('SEL-08: en la página, una ventana más corta que un turno se dice en el campo y no rompe', function () {
    $s = adminScope();

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->set('startsOn', now()->addDays(3)->format('Y-m-d'))
        ->set('windowStarts', '09:00')
        ->set('windowEnds', '09:15')
        ->assertHasErrors(['window_starts' => 'La ventana diaria debe durar al menos 30 minutos, lo que dura un turno.']);
});

describe('el CSV temporal (lleva datos personales) se borra siempre', function () {
    beforeEach(function () {
        $this->scope = adminScope();
        $this->page = Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
            ->set('termId', $this->scope['term']->id)
            ->set('modalityId', $this->scope['modality']->id)
            ->set('departmentId', $this->scope['department']->id)
            ->call('start')
            ->call('goTo', 2);
    });

    it('cuando el archivo no pasa la validación', function () {
        $this->page->set('csvFile', UploadedFile::fake()->createWithContent('criterios.xlsx', ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\n"));
        $file = $this->page->get('csvFile');
        expect($file->exists())->toBeTrue();

        $this->page->call('importCsv')->assertHasErrors('csvFile')->assertSet('csvFile', null);

        expect($file->exists())->toBeFalse();
    });

    it('cuando se sube otro en su lugar', function () {
        $this->page->set('csvFile', UploadedFile::fake()->createWithContent('primero.csv', ADMIN_HEADER));
        $first = $this->page->get('csvFile');

        $this->page->set('csvFile', UploadedFile::fake()->createWithContent('segundo.csv', ADMIN_HEADER));

        expect($first->exists())->toBeFalse()
            ->and($this->page->get('csvFile')->exists())->toBeTrue();
    });

    it('cuando sale del paso sin importarlo', function () {
        $this->page->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER));
        $file = $this->page->get('csvFile');

        $this->page->call('goTo', 1)->assertSet('csvFile', null);

        expect($file->exists())->toBeFalse();
    });

    it('después de importarlo', function () {
        Teacher::factory()->adscribedTo($this->scope['department'])->create(['name' => 'Ana Pérez Lara']);
        $this->page->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\n"));
        $file = $this->page->get('csvFile');

        $this->page->call('importCsv')->assertHasNoErrors()->assertSet('csvFile', null);

        expect($file->exists())->toBeFalse();
    });
});

it('SEL-07: el monitor y la vista previa marcan «Puesto administrativo» y el jefe va primero', function () {
    $selection = Selection::factory()->create(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();
    $boss = Teacher::factory()->create(['name' => 'Rosa Jefa Soto', 'user_id' => $head->id]);
    $plain = Teacher::factory()->create(['name' => 'Luis Docente Ruiz']);
    SelectionEntry::factory()->for($selection)->create(['teacher_id' => $boss->id, 'administrative_post' => 'Jefe de departamento académico', 'position' => 1, 'slot_index' => 0, 'slot_starts_at' => now()->subMinutes(5), 'slot_ends_at' => now()->addMinutes(25)]);
    SelectionEntry::factory()->for($selection)->create(['teacher_id' => $plain->id, 'position' => 2, 'slot_index' => 1, 'slot_starts_at' => now()->addMinutes(25), 'slot_ends_at' => now()->addMinutes(55)]);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin', ['seleccion' => $selection->id])
        ->assertSee('Puesto administrativo')
        ->assertSeeInOrder(['Rosa Jefa Soto', 'Puesto administrativo', 'Luis Docente Ruiz']);
});

it('SEL-07: la vista previa del paso 6 pone aparte a quien tiene puesto administrativo', function () {
    $s = adminScope();
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $s['department']->id])->create();
    Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Rosa Jefa Soto', 'user_id' => $head->id]);
    Teacher::factory()->adscribedTo($s['department'])->create(['name' => 'Ana Pérez Lara']);

    Livewire::actingAs($this->admin)->test('staffing::pages.selection-admin')
        ->set('termId', $s['term']->id)
        ->set('modalityId', $s['modality']->id)
        ->set('departmentId', $s['department']->id)
        ->call('start')
        ->call('goTo', 2)
        ->set('csvFile', UploadedFile::fake()->createWithContent('criterios.csv', ADMIN_HEADER."ana perez lara;40;si;si;si;si;12\nrosa jefa soto;20;si;si;si;si;3\n"))
        ->call('importCsv')
        ->call('setSpace', $s['subject']->id, 'aula')
        ->set('startsOn', now()->addDays(3)->format('Y-m-d'))
        ->call('schedule')
        ->call('goTo', 6)
        ->assertSeeHtml('data-testid="preview-admin"')
        ->assertSee('Puesto administrativo');
});
