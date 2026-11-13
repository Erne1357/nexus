<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| Tarea 3.4: el tablero de choques y avance (`/scheduling/conflicts`, scheduling::pages.conflicts). Vista principal
| por carrera, retícula y semestre; vista por departamento con drawer (decidido por el usuario el 7 de noviembre de
| 2026). Lo ven quienes ven la carga y el coordinador de su carrera; nadie más. Es de la carga de una modalidad (M1,
| `?modalidad=`) con el campus como filtro opcional (`?campus=`).
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010', 'name' => 'Plan 2010', 'is_current' => true, 'semesters_count' => 9]);
    $this->subject = Subject::factory()->create(['code' => 'SCD-1015', 'name' => 'Lenguajes y Autómatas I', 'department_id' => departmentKey(10)]);
    $this->clash = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id,
        'owner_career_id' => $this->career->id, 'study_plan_id' => $this->plan->id, 'semester' => 5, 'code' => 'D', 'teacher_id' => Teacher::factory()->create()->id]);
    GroupSession::factory()->for($this->clash)->on(1, '10:00', '12:00')->create();
    ScheduleConflict::factory()->forGroup($this->clash)->create(['rule' => 'HC-01', 'message' => 'Ana Pérez ya da Programación Web, grupo D el lunes de 10:00 a 12:00.']);
    $this->admin = User::factory()->admin()->create();
});

function conflictsPage(User $user, array $query = [])
{
    onCampusPage(test()->campus);

    return Livewire::withQueryParams($query)->actingAs($user)->test('scheduling::pages.conflicts');
}

describe('quién entra', function () {
    it('lo ven quienes ven la carga y el coordinador, con su entrada en el menú', function (Role $role, Closure $scope) {
        $user = User::factory()->withRole($role, $scope($this))->create();

        $this->actingAs($user)->get(route('scheduling.conflicts'))->assertOk()->assertSee('Choques y avance');
    })->with([
        'subdirección' => [Role::Subdireccion, fn () => []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn ($test) => ['campus_id' => $test->campus->id, 'department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn ($test) => ['campus_id' => $test->campus->id, 'career_id' => $test->career->id]],
    ]);

    it('el docente no entra ni llama sus métodos', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($docente)->get(route('scheduling.conflicts'))->assertForbidden();
        $component = conflictsPage($this->admin);
        $this->actingAs($docente);
        $component->call('recompute')->assertForbidden();
    });

    it('al invitado lo manda al login', function () {
        $this->get(route('scheduling.conflicts'))->assertRedirect(route('login'));
    });
});

describe('vista por carrera y semestre', function () {
    it('muestra la pestaña de cada carrera, su cuadrícula y el detalle de la celda elegida', function () {
        conflictsPage($this->admin)
            ->assertSee('Ingeniería en Sistemas Computacionales')
            ->assertSeeHtml('data-testid="career-tab-ISC"')
            ->assertSeeHtml('data-testid="cell-ISIC-2010-5"')
            ->call('selectCell', $this->plan->id, 5)
            ->assertSet('cell', "{$this->plan->id}:5")
            ->assertSee('Ana Pérez ya da Programación Web, grupo D el lunes de 10:00 a 12:00.')
            ->assertSee('SCD-1015 · D')
            ->assertSee('Corregir en el editor')
            ->assertDontSee('El editor de horarios llega con la tarea 3.5.');
    });

    it('tocar otra vez la celda la cierra y una celda que no existe se ignora', function () {
        conflictsPage($this->admin, ['celda' => 'no-existe:3'])
            ->assertDontSeeHtml('data-testid="cell-detail"')
            ->call('selectCell', $this->plan->id, 5)
            ->call('selectCell', $this->plan->id, 5)
            ->assertSet('cell', '');
    });

    it('el coordinador de otra carrera no ve los grupos de esta', function () {
        $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();

        conflictsPage($other)
            ->assertSee('Todavía no hay grupos que puedas ver en Presencial.')
            ->assertDontSee('Ana Pérez');
    });
});

describe('vista por departamento', function () {
    it('cuenta por departamento y el drawer lista lo que falta', function () {
        conflictsPage($this->admin, ['vista' => 'departamentos'])
            ->assertSeeHtml('data-testid="department-matrix"')
            ->call('openDepartment', departmentKey(10))
            ->assertSet('showDrawer', true)
            ->assertSee('Ana Pérez ya da Programación Web');
    });

    it('no abre el drawer de un departamento fuera de su alcance', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();

        conflictsPage($jefe, ['vista' => 'departamentos'])->call('openDepartment', departmentKey(10))->assertNotFound();
    });
});

it('una vista desconocida en la URL muestra la vista por carrera y semestre', function () {
    conflictsPage($this->admin, ['vista' => 'x'])
        ->assertSet('view', 'reticula')
        ->assertSeeHtml('data-testid="career-grid"');
});

it('cerrar el drawer con Esc o el velo olvida el departamento', function () {
    conflictsPage($this->admin, ['vista' => 'departamentos'])
        ->call('openDepartment', departmentKey(10))
        ->set('showDrawer', false)
        ->assertSet('departmentId', null);
});

it('sin ninguna modalidad activa donde trabaje, responde 403', function () {
    Modality::query()->update(['is_active' => false]);

    Livewire::actingAs($this->admin)->test('scheduling::pages.conflicts')->assertForbidden();
});

it('M1: sin campus cuenta la carga de la modalidad en todos sus campus; ?campus= la filtra, y otra modalidad no cuenta', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $there = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $c1->id, 'subject_id' => $this->subject->id,
        'owner_career_id' => $this->career->id, 'study_plan_id' => $this->plan->id, 'semester' => 5]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => modalityOf('EAD')->id,
        'subject_id' => $this->subject->id, 'owner_career_id' => $this->career->id, 'study_plan_id' => $this->plan->id, 'semester' => 5]);
    onCampusPage(null);

    $all = Livewire::actingAs($this->admin)->test('scheduling::pages.conflicts')
        ->assertSee('Lo que le falta a la carga de Presencial para cerrar')
        ->assertSeeHtml('data-testid="campus-filter-todos"');
    $c1Only = Livewire::withQueryParams(['campus' => 'C1'])->actingAs($this->admin)->test('scheduling::pages.conflicts')
        ->assertSee('Lo que le falta a la carga de Presencial en Campus I para cerrar');

    expect(textOf(domOf($all)->querySelector('[data-testid="career-tab-ISC"]')))->toContain('2')
        ->and(textOf(domOf($c1Only)->querySelector('[data-testid="career-tab-ISC"]')))->toContain('1')
        ->and($there->campus_id)->toBe($c1->id);
});

it('MS6: el coordinador limitado a C2 filtra cualquier campus de su modalidad (la lee toda); uno inactivo o que no existe se ignora', function () {
    $other = Campus::factory()->create(['code' => 'ZZ', 'name' => 'Campus Z']);
    Campus::factory()->inactive()->create(['code' => 'YY', 'name' => 'Campus Y']);
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();

    Livewire::withQueryParams(['campus' => $other->code])->actingAs($coordinator)->test('scheduling::pages.conflicts')
        ->assertSee('Lo que le falta a la carga de Presencial en Campus Z para cerrar');

    foreach (['YY', 'XX'] as $ignored) {
        Livewire::withQueryParams(['campus' => $ignored])->actingAs($coordinator)->test('scheduling::pages.conflicts')
            ->assertSee('Lo que le falta a la carga de Presencial para cerrar')->assertDontSee('Campus Y');
    }
});

it('U2: el filtro de campus lleva los grupos que el usuario ve en cada campus, y cada grupo pendiente su etiqueta y el editor en su modalidad', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $c1->id, 'subject_id' => $this->subject->id,
        'owner_career_id' => $this->career->id, 'study_plan_id' => $this->plan->id, 'semester' => 5]);
    onCampusPage(null);

    $page = Livewire::actingAs($this->admin)->test('scheduling::pages.conflicts')->call('selectCell', $this->plan->id, 5);
    $dom = domOf($page);

    expect(textOf($dom->querySelector('[data-testid="campus-count-todos"]')))->toBe('2')
        ->and(textOf($dom->querySelector('[data-testid="campus-count-C1"]')))->toBe('1')
        ->and(textOf($dom->querySelector('[data-testid="campus-count-C2"]')))->toBe('1')
        ->and($dom->querySelector('[data-testid="conflict-group-'.$this->clash->id.'"] [title="Campus II"]'))->not->toBeNull()
        ->and($page->html())->toContain(e(route('scheduling.editor', ['modalidad' => 'PRES', 'campus' => 'C2', 'grupo' => $this->clash->id])));
});

it('otra modalidad: el coordinador de la carrera en EAD no ve la carga presencial aunque pida ?modalidad=PRES', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $ead = User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('EAD')->id, 'career_id' => $this->career->id])->create();

    conflictsPage($ead, ['modalidad' => 'PRES'])
        ->assertSee('Lo que le falta a la carga de EAD')
        ->assertSeeHtml('data-testid="conflicts-empty"')
        ->assertDontSee('SCD-1015');
});

it('Recalcular encola el recálculo de la carga de la modalidad, aunque haya un campus en el filtro', function () {
    Queue::fake([RecomputeScheduleConflicts::class]);

    $component = conflictsPage($this->admin, ['campus' => 'C2'])->call('recompute');

    Queue::assertPushed(RecomputeScheduleConflicts::class, fn (RecomputeScheduleConflicts $job): bool => $job->termId === $this->term->id && $job->modalityId === modalityOf('PRES')->id);
    expect(lastToastOf($component)['title'])->toBe('Recalculando los choques')
        ->and($component->get('pollUntil'))->toBeGreaterThan(now()->getTimestamp());
    $component->assertSeeHtml('wire:poll.3s="refreshBoard"');

    $this->travel(31)->seconds();
    $component->call('refreshBoard')->assertSet('pollUntil', 0)->assertDontSeeHtml('wire:poll.3s="refreshBoard"');
});

it('fija la modalidad que resolvió: si después se abre otra antes por clave, el tablero no cambia de modalidad', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $page = conflictsPage($this->admin)->assertSet('modalidad', 'EAD');

    TermOpening::query()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'opened_by' => $this->admin->id, 'opened_at' => now()]);

    $page->call('refreshBoard')->assertSet('modalidad', 'EAD')->assertSee('Lo que le falta a la carga de EAD');
});
