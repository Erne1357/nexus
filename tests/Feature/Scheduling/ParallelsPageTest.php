<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| La pestaña «Paralelos» de la bandeja del departamento (tarea 8.8, opción B; DESIGN.md, «Paralelos»): el jefe del
| departamento que imparte ve por materia y campus lo que piden las carreras contra lo que bastan juntándolas, abre el
| drawer, mueve esperados entre completos y mixtos con la vista previa de ParallelDraft y arma con ArmParallelGroups. Quien
| solo ve la bandeja (jefe de proyecto docente, Subdirección) la consulta; el coordinador y el jefe de otro departamento no
| entran.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    TermOpening::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->world->modality->id]);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isic = $this->world->career('ISIC', null, $this->c2);
    $this->iind = $this->world->career('IIND', null, $this->c2);

    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    $this->algebra = Subject::factory()->create(['code' => 'ACF-0903', 'name' => 'Álgebra Lineal', 'department_id' => $this->world->department->id, 'needs_lab' => false]);
    $this->world->request($this->isic, 10, 1, subject: $this->algebra, campus: $this->c2);
    $this->world->request($this->iind, 10, 1, subject: $this->algebra, campus: $this->c2);
    $this->chemistry = Subject::factory()->create(['code' => 'AEC-1058', 'name' => 'Química', 'department_id' => $this->world->department->id]);
    $this->world->request($this->isic, 30, 1, subject: $this->chemistry);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
});

/**
 * @param  array<string, string>  $query
 */
function parallelsPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('scheduling::pages.parallels');
}

function parallelsPageOpen(Testable $page): Testable
{
    return $page->call('open', test()->world->subject->id, test()->world->campus->id);
}

it('muestra las cuatro cifras por el filtro de campus, la búsqueda y lo que no hay que juntar con su motivo', function () {
    parallelsPageAs($this->jefe)
        ->assertOk()
        ->assertSeeHtml('data-testid="parallels-figures"')
        ->assertSeeInOrder(['Materias', '2', 'Grupos pedidos', '6', 'Bastan juntando', '4', 'De menos', '−2'])
        ->assertSee('ACF-0902')
        ->assertSee('ACF-0903')
        ->assertSee('Solo la pide ISIC')
        ->set('campus', 'C2')
        ->assertViewHas('figures', ['subjects' => 1, 'requested' => 2, 'suggested' => 1, 'saved' => 1, 'arranged' => 0])
        ->assertDontSeeHtml('data-testid="parallels-row-ACF-0902-C1"')
        ->assertSeeHtml('data-testid="parallels-row-ACF-0903-C2"')
        ->set('campus', null)
        ->set('search', 'algebra')
        ->assertSeeHtml('data-testid="parallels-row-ACF-0903-C2"')
        ->assertDontSeeHtml('data-testid="parallels-row-ACF-0902-C1"')
        ->set('search', '')
        ->set('filter', 'armadas')
        ->assertSee('Ninguna materia con esos filtros.');
});

it('el drawer muestra la matriz con la sugerencia y su vista previa recalcula al mover un número', function () {
    $page = parallelsPageOpen(parallelsPageAs($this->jefe))
        ->assertSet('showDrawer', true)
        ->assertSet('full', [$this->iind->id => 1, $this->isic->id => 1])
        ->assertSet('mixed', [[$this->isic->id => 25, $this->iind->id => 5]])
        ->assertSeeHtml('data-testid="parallels-matrix"')
        ->assertSee('Cálculo Integral en Campus I')
        ->assertSee('Grupos que resultan · 3')
        ->assertSeeInOrder(['Nacen', '3 grupos nuevos', 'sin horario: 2 completos y Mixto 1.'])
        ->assertDontSee('Usar lo de Nexus');

    $page->set("mixed.0.{$this->iind->id}", 3)
        ->assertSee('Quedan de menos 2 de IIND: no caben en ningún grupo.')
        ->assertSee('Usar lo de Nexus')
        ->call('step', $this->iind->id, 1)
        ->assertSet("full.{$this->iind->id}", 2)
        ->assertSee('Grupos que resultan · 4')
        ->call('useSuggestion')
        ->assertSet('full', [$this->iind->id => 1, $this->isic->id => 1])
        ->call('addMixed')
        ->assertSee('Mixto 2')
        ->call('removeMixed', 1)
        ->assertDontSee('Mixto 2');
});

it('HC-07: un mixto que pasa su capacidad se marca en su columna y no deja armar', function () {
    parallelsPageOpen(parallelsPageAs($this->jefe))
        ->set("mixed.0.{$this->iind->id}", 13)
        ->assertSeeHtml('data-testid="parallels-over-0"')
        ->assertSee('Mixto 1 pasa su capacidad por 3 lugares (HC-07): quita esperados de ese grupo.')
        ->assertSeeHtml('data-testid="parallels-arm" disabled');
});

it('arma los grupos, cierra el drawer y la lista marca la materia armada', function () {
    parallelsPageOpen(parallelsPageAs($this->jefe))
        ->call('arm')
        ->assertHasNoErrors()
        ->assertSet('showDrawer', false)
        ->assertSeeHtml('data-testid="parallels-state-ACF-0902-C1"')
        ->assertSee('Armada');

    $groups = Group::query()->where('subject_id', $this->world->subject->id)->with('quotas')->orderBy('code')->get();

    expect($groups)->toHaveCount(3)
        ->and($groups->map(fn (Group $group): array => $group->quotas->pluck('max_seats', 'career_id')->all())->all())
        ->toContain([$this->isic->id => 25, $this->iind->id => 5]);
});

it('una materia armada se vuelve a abrir con sus grupos, no con la sugerencia', function () {
    $page = parallelsPageOpen(parallelsPageAs($this->jefe))
        ->set("mixed.0.{$this->isic->id}", 22)
        ->set("mixed.0.{$this->iind->id}", 8)
        ->call('arm')
        ->assertHasNoErrors();

    parallelsPageOpen($page)
        ->assertSet('mixed', [[$this->isic->id => 22, $this->iind->id => 8]])
        ->assertSee('Usar lo de Nexus');
});

it('una materia con una parte pendiente se ve «Pendiente» con su motivo y «Ver», y no cuenta como por armar', function () {
    $this->world->request($this->world->career('ILOG', null, $this->c2), 30, 1, 'pending', subject: $this->algebra, campus: $this->c2);

    parallelsPageAs($this->jefe)
        ->assertSeeHtml('data-testid="parallels-open-ACF-0903-C2">Ver</button>')
        ->assertSeeHtml('data-testid="parallels-open-ACF-0902-C1">Armar</button>')
        ->assertSee('Falta aceptar la parte de ILOG: sus lugares nacen al aceptarla.')
        ->assertViewHas('filterCounts', ['' => 2, 'por-armar' => 1, 'pendientes' => 1, 'armadas' => 0])
        ->assertViewHas('pendingCount', 1)
        ->set('filter', 'pendientes')
        ->assertSeeHtml('data-testid="parallels-row-ACF-0903-C2"')
        ->assertDontSeeHtml('data-testid="parallels-row-ACF-0902-C1"');
});

it('al cancelar un grupo con horario dice qué horario se pierde', function () {
    $isic = $this->world->request($this->isic, 20, 1, subject: $this->chemistry, campus: $this->c2);
    $iind = $this->world->request($this->iind, 10, 1, subject: $this->chemistry, campus: $this->c2);
    $this->world->groupsOf($isic);
    [$lost] = $this->world->groupsOf($iind);
    $this->world->withSession($lost, hours: 2);
    $this->world->withSession($this->world->groupsOf($isic)[0], hours: 3);

    $page = parallelsPageAs($this->jefe)->call('open', $this->chemistry->id, $this->c2->id)
        ->assertSeeInOrder(['Se cancela', $lost->code, 'de IIND y se quitan sus 2 h colocadas']);

    $page->call('arm')->assertHasNoErrors()->assertSet('showDrawer', false);

    expect($lost->fresh()->status)->toBe(GroupStatus::Cancelled);
});

it('el grupo con docente sin lugar se dice en lo que pasa y no deja armar', function () {
    $isic = $this->world->request($this->isic, 20, 1, subject: $this->algebra);
    $iind = $this->world->request($this->iind, 10, 1, subject: $this->algebra);
    [$first] = $this->world->groupsOf($isic);
    [$second] = $this->world->groupsOf($iind);
    $this->world->withTeacher($first);
    $this->world->withTeacher($second);

    parallelsPageAs($this->jefe)->call('open', $this->algebra->id, $this->world->campus->id)
        ->assertSeeHtml('data-testid="parallels-blocked"')
        ->assertSee("El grupo {$second->code} de IIND tiene docente y el arreglo no le deja lugar")
        ->assertSeeHtml('data-testid="parallels-arm" disabled');
});

it('el error de la Action se muestra en su lugar', function () {
    CareerCampus::query()->where('career_id', $this->iind->id)->where('campus_id', $this->world->campus->id)->update(['is_active' => false]);

    parallelsPageOpen(parallelsPageAs($this->jefe))
        ->call('arm')
        ->assertHasErrors('groups')
        ->assertSet('showDrawer', true)
        ->assertSeeHtml('data-testid="parallels-error"')
        ->assertSee('IIND no está activa u ofrecida en C1 (HC-13).');
});

it('con la carga no editable lo dice y no deja armar', function () {
    $this->world->load->update(['status' => LoadStatus::Published]);

    parallelsPageOpen(parallelsPageAs($this->jefe))
        ->assertSee('La carga de Ciencias Básicas en Presencial ya está publicada: no se editan paralelos.')
        ->assertSeeHtml('data-testid="parallels-arm" disabled')
        ->call('arm')
        ->assertHasErrors('groups');

    expect(Group::query()->count())->toBe(0);
});

it('el jefe de proyecto docente y Subdirección la consultan: «Ver» en lugar de «Armar» y el botón deshabilitado con su motivo', function (Role $role) {
    $user = User::factory()->withRole($role, $role === Role::JefeProyectoDocente ? ['modality_id' => $this->world->modality->id, 'department_id' => $this->world->department->id] : [])->create();

    $page = parallelsPageAs($user, ['depto' => 'CB'])
        ->assertSee('Solo consulta')
        ->assertSeeHtml('data-testid="parallels-open-ACF-0902-C1">Ver</button>')
        ->assertDontSeeHtml('>Armar</button>');

    parallelsPageOpen($page)
        ->assertSee('Solo consulta: arma los grupos la jefatura de Ciencias Básicas.')
        ->assertSeeHtml('data-testid="parallels-arm" disabled')
        ->assertDontSeeHtml('wire:model.live.debounce.400ms="mixed.')
        ->assertDontSeeHtml('wire:click="step(')
        ->call('arm')
        ->assertForbidden();
})->with([Role::JefeProyectoDocente, Role::Subdireccion]);

it('el jefe de otro departamento y el coordinador no la ven', function () {
    $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create(['code' => 'SC'])->id])->create();
    $coordinator = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->world->modality->id, 'career_id' => $this->isic->id])->create();

    parallelsPageAs($other, ['depto' => 'CB'])->assertForbidden();
    parallelsPageAs($coordinator)->assertForbidden();
});

it('la bandeja y Paralelos son pestañas de la misma pantalla, con la modalidad y el departamento', function () {
    $query = ['modalidad' => 'PRES', 'depto' => 'CB'];

    Livewire::withQueryParams($query)->actingAs($this->jefe)->test('academic-load::pages.inbox')
        ->assertSeeHtml('data-testid="inbox-tabs"')
        ->assertSeeHtml('href="'.e(route('scheduling.parallels', $query)).'"');

    parallelsPageAs($this->jefe, $query)
        ->assertSeeHtml('data-testid="inbox-tabs"')
        ->assertSeeHtml('href="'.e(route('academic-load.inbox', $query)).'"');

    Livewire::withQueryParams($query)->actingAs($this->jefe)->test('academic-load::pages.inbox')
        ->call('openParallels', $this->world->subject->id, $this->world->campus->id)
        ->assertSeeHtml('data-testid="parallels-arm-link"')
        ->assertSeeHtml('href="'.e(route('scheduling.parallels', [...$query, 'campus' => 'C1', 'buscar' => 'ACF-0902'])).'"');

    expect(app(Navigation::class)->forRoute('scheduling.parallels')?->route)->toBe('academic-load.inbox');
});
