<?php

use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/*
| Página de inicio (`/`, core::pages.home): saludo y un bloque por cada rol que el usuario ejerce
| según Actor::roles(). El admin, que tiene todos los roles, ve solo el suyo. Los bloques se
| prueban con el componente solo, porque la credencial de la banda también nombra el rol.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

it('saluda al usuario y muestra solo el bloque del perfil activo', function () {
    $user = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::Docente, ['campus_id' => $this->campus->id])
        ->create(['name' => 'Ana López']);

    actingAsRole($user, Role::JefeDepartamento);

    Livewire::test('core::pages.home')
        ->assertSee('Hola, Ana López')
        ->assertSeeInOrder(['Jefe de departamento académico', 'Validas los catálogos'])
        ->assertDontSee('Consultas tu propia carga')
        ->assertDontSee('Coordinador de carrera')
        ->assertDontSee('Administración');

    actingAsRole($user, Role::Docente);

    Livewire::test('core::pages.home')
        ->assertSee('Consultas tu propia carga')
        ->assertDontSee('Validas los catálogos');
});

it('al coordinador le muestra solo el bloque de coordinación de carrera', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create());

    Livewire::test('core::pages.home')
        ->assertSee('Coordinador de carrera')
        ->assertSee('propones qué materias y cuántos grupos abrir')
        ->assertDontSee('Jefe de departamento académico')
        ->assertDontSee('Administración');
});

it('al admin le muestra el bloque de administración y no uno por cada rol', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('core::pages.home')
        ->assertSee('Administración')
        ->assertSee('Tienes acceso a todo Nexus')
        ->assertDontSee('Coordinador de carrera')
        ->assertDontSee('Jefe de departamento académico');
});

it('a quien no tiene un rol vigente le dice que pida uno', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
    RoleAssignment::where('user_id', $user->id)->update(['valid_to' => today()->subDay()]);

    $this->actingAs($user);

    Livewire::test('core::pages.home')
        ->assertSee('Todavía no tienes un rol vigente en Nexus')
        ->assertDontSee('Coordinador de carrera');
});

it('se muestra dentro del layout de la aplicación', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('<title>Inicio · Nexus</title>', false)
        ->assertSee('Navegación principal')
        ->assertSee('Cerrar sesión');
});

it('los bloques de rol son superficies planas, sin tarjeta con sombra', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('core::pages.home')
        ->assertSeeHtml('data-testid="role-block"')
        ->assertDontSeeHtml('shadow-xl');
});

it('un invitado va al login', function () {
    $this->get('/')->assertRedirect('/login');
});

/*
| Bloque «Calendario» de Inicio (tarea 2.1, maqueta B): las etapas en curso, con los días que quedan, y las dos que
| siguen, del periodo y el campus activos; la ventana propia del campus gana a la general. Solo lectura; se oculta sin
| periodo activo o sin etapas con fecha.
*/

function homeCalendarUser(Campus $campus): User
{
    return User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
}

function homeCalendarStage(Term $term, Stage $stage, ?Campus $campus, string $from, string $to): void
{
    app(SetTermStage::class)->handle($term, $stage, $campus, $from, $to);
}

describe('calendario en Inicio', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-12 10:00:00');
        $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'code' => '20271']);
    });

    it('muestra la etapa en curso con sus días y las dos que siguen, y no más', function () {
        homeCalendarStage($this->term, Stage::CatalogValidation, null, '2026-11-02', '2026-11-05');
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');
        homeCalendarStage($this->term, Stage::Scheduling, null, '2026-11-20', '2026-12-04');
        homeCalendarStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');
        homeCalendarStage($this->term, Stage::Rooms, null, '2026-11-30', '2026-12-04');

        $this->actingAs(homeCalendarUser($this->campus));

        Livewire::test('core::pages.home')
            ->assertSeeInOrder(['Calendario', 'Ene-Jun 2027 · Campus', 'Propuesta por carrera', 'En curso', '10–19 nov', 'quedan 7 días', 'Horarios y cupos', 'Próxima', 'empieza en 8 días', 'Asignación docente', 'empieza en 12 días'])
            ->assertDontSee('Validación de catálogos')
            ->assertDontSee('Salones y acuerdos');
    });

    it('para el campus activo, su ventana propia gana a la general', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');
        homeCalendarStage($this->term, Stage::Proposal, $this->campus, '2026-11-11', '2026-11-30');

        $this->actingAs(homeCalendarUser($this->campus));

        Livewire::test('core::pages.home')
            ->assertSee('11–30 nov')
            ->assertSee('quedan 18 días')
            ->assertDontSee('10–19 nov');
    });

    it('lo ve cualquier usuario con un rol vigente', function (Role $role, array $scope) {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');

        $this->actingAs(User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create());

        Livewire::test('core::pages.home')->assertSee('Propuesta por carrera')->assertSee('quedan 7 días');
    })->with([
        'docente' => [Role::Docente, []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    ]);

    it('se oculta sin etapas con fecha', function () {
        $this->actingAs(homeCalendarUser($this->campus));

        Livewire::test('core::pages.home')->assertDontSee('Calendario')->assertDontSee('Propuesta por carrera');
    });

    it('se oculta si ya terminaron todas las etapas', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-10-01', '2026-10-10');

        $this->actingAs(homeCalendarUser($this->campus));

        Livewire::test('core::pages.home')->assertDontSee('Propuesta por carrera');
    });

    it('enlaza la ficha del periodo a quien puede verla y a nadie más', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test('core::pages.home')->assertSeeHtml('href="'.route('core.terms.show', $this->term).'"')->assertSee('Ver el periodo');

        $this->actingAs(homeCalendarUser($this->campus));
        Livewire::test('core::pages.home')->assertSee('Propuesta por carrera')->assertDontSee('Ver el periodo');
    });

    it('explica la línea de hoy en una leyenda', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');

        $this->actingAs(homeCalendarUser($this->campus));
        Livewire::test('core::pages.home')->assertSee('Hoy, 12 nov');
    });

    it('se oculta sin periodo activo', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');
        $this->term->update(['is_current' => false]);

        $this->actingAs(homeCalendarUser($this->campus));

        Livewire::test('core::pages.home')->assertDontSee('Propuesta por carrera');
    });

    it('se oculta a quien no tiene un rol vigente', function () {
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');

        $user = homeCalendarUser($this->campus);
        RoleAssignment::where('user_id', $user->id)->update(['valid_to' => today()->subDay()]);
        $this->actingAs($user);

        Livewire::test('core::pages.home')->assertDontSee('Propuesta por carrera');
    });

    it('sin campus activo usa el calendario general', function () {
        $other = Campus::factory()->create(['code' => 'C1']);
        homeCalendarStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');
        homeCalendarStage($this->term, Stage::Proposal, $this->campus, '2026-11-11', '2026-11-30');

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('core::pages.home')->assertSee('10–19 nov')->assertSee('Ene-Jun 2027');
    });
});
