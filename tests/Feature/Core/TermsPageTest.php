<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Catálogo de Periodos (`/terms`, core::pages.terms). Lo administran el `admin` y la `subdireccion`
| (reglas-de-negocio.md, "Aprobaciones y permisos"); los demás roles no entran. Cada método público
| del componente vuelve a autorizar, aunque se llame directo con una página que abrió otra sesión.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->t1 = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->t2 = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    $this->admin = User::factory()->admin()->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])->create();
});

/**
 * @return Closure(mixed): bool
 */
function listsTerms(array $codes): Closure
{
    return fn ($terms): bool => $terms->pluck('code')->all() === $codes;
}

function seesTermsMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Periodos');
}

describe('admin y subdirección', function () {
    it('ven la tabla con código, nombre, fechas y estado, del más reciente al más antiguo', function (string $who) {
        $this->actingAs($this->{$who})
            ->get(route('core.terms'))
            ->assertOk()
            ->assertSeeInOrder(['Código', 'Nombre', 'Inicio', 'Fin', 'Estado'])
            ->assertSeeInOrder(['20271', 'Ene-Jun 2027', '18/01/2027', '11/06/2027', 'Actual', '20263', 'Ago-Dic 2026', '17/08/2026']);
    })->with(['admin', 'subdireccion']);

    it('busca por código o nombre', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->assertViewHas('terms', listsTerms(['20271', '20263']))
            ->set('search', 'ago')
            ->assertViewHas('terms', listsTerms(['20263']))
            ->set('search', '20271')
            ->assertViewHas('terms', listsTerms(['20271']));
    });

    it('da de alta un periodo desde el modal y lo confirma con un toast', function (string $who) {
        $page = Livewire::actingAs($this->{$who})
            ->test('core::pages.terms')
            ->call('create')
            ->assertSet('showModal', true)
            ->set('form.code', '20272')
            ->set('form.starts_on', '2027-06-21')
            ->set('form.ends_on', '2027-07-30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect(Term::where('code', '20272')->sole())
            ->name->toBe('Verano 2027')
            ->is_current->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Periodo creado')->description->toBe('20272 · Verano 2027');
    })->with(['admin', 'subdireccion']);

    it('sugiere el nombre con el código mientras el nombre esté vacío o sea la sugerencia anterior', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->call('create')
            ->set('form.code', '20271x')
            ->assertSet('form.name', '')
            ->set('form.code', '20273')
            ->assertSet('form.name', 'Ago-Dic 2027')
            ->set('form.code', '20281')
            ->assertSet('form.name', 'Ene-Jun 2028')
            ->set('form.name', 'Mi nombre')
            ->set('form.code', '20282')
            ->assertSet('form.name', 'Mi nombre');
    });

    it('edita un periodo', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->call('edit', $this->t2->id)
            ->assertSet('showModal', true)
            ->assertSet('form.code', '20263')
            ->assertSet('form.starts_on', '2026-08-17')
            ->set('form.name', 'Otoño 2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->t2->fresh()->name)->toBe('Otoño 2026')
            ->and(lastToastOf($page)['title'])->toBe('Periodo actualizado');
    });

    it('marca un periodo como actual y quita la marca al anterior', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.terms');

        $page->call('makeCurrent', $this->t2->id);

        expect($this->t2->fresh()->is_current)->toBeTrue()
            ->and($this->t1->fresh()->is_current)->toBeFalse()
            ->and(lastToastOf($page))->title->toBe('Periodo actual')->description->toBe('20263 · Ago-Dic 2026');
    });

    it('deshabilita «Marcar como actual» con su motivo en el periodo actual, y pide confirmación en los demás', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->assertSee('Ya es el periodo actual.')
            ->assertSee('¿Marcar el periodo 20263 como el actual?', escape: false);
    });

    it('muestra en español los errores de validación del formulario', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->call('create')
            ->set('form.starts_on', 'mañana')
            ->call('save')
            ->assertHasErrors(['form.code' => 'required', 'form.name' => 'required', 'form.starts_on' => 'date_format', 'form.ends_on' => 'required'])
            ->assertSee('El campo código es obligatorio.')
            ->assertSee('El campo nombre es obligatorio.')
            ->assertSee('El campo inicio debe ser una fecha válida.')
            ->assertSee('El campo fin es obligatorio.')
            ->assertSet('showModal', true);

        expect(Term::count())->toBe(2);
    });

    it('muestra en español las reglas de las Actions: código con forma, repetido y fechas en orden', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.terms')
            ->call('create')
            ->set('form.code', '2027')
            ->set('form.name', 'X')
            ->set('form.starts_on', '2027-06-21')
            ->set('form.ends_on', '2027-07-30')
            ->call('save')
            ->assertHasErrors('form.code')
            ->assertSee('El código lleva el año y el periodo');

        $page->set('form.code', '20271')
            ->call('save')
            ->assertHasErrors('form.code')
            ->assertSee('Ya existe un periodo con el código 20271.');

        $page->set('form.code', '20272')
            ->set('form.ends_on', '2027-06-01')
            ->call('save')
            ->assertHasErrors('form.ends_on')
            ->assertSee('La fecha de fin no puede ser anterior a la de inicio.');

        expect(Term::count())->toBe(2);
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

        $this->actingAs($user)->get(route('core.terms'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (Role $role, array $scope, string $method, bool $editFirst) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.terms');

        if ($editFirst) {
            $page->call('edit', $this->t2->id)->set('form.name', 'Otro');
        } elseif ($method === 'save') {
            $page->call('create')->set('form.code', '20272')->set('form.name', 'Verano')->set('form.starts_on', '2027-06-21')->set('form.ends_on', '2027-07-30');
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'makeCurrent'], true) ? [$this->t2->id] : []))->assertForbidden();

        expect(Term::count())->toBe(2)
            ->and($this->t2->fresh())->name->toBe('Ago-Dic 2026')->is_current->toBeFalse()
            ->and(Activity::count())->toBe(0);
    })->with($roles)->with([
        'nuevo periodo' => ['create', false],
        'editar' => ['edit', false],
        'marcar como actual' => ['makeCurrent', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
    ]);

    it('no pueden sugerir el nombre con el código aunque la página la abriera el admin', function (bool $editing) {
        $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.terms');
        $editing ? $page->call('edit', $this->t2->id) : $page->call('create');

        $this->actingAs($user);
        $page->set('form.code', '20272')->assertForbidden();
    })->with([false, true]);

    it('un invitado va al login', function () {
        $this->get(route('core.terms'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Periodos apunta a la página de periodos', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Periodos');

        expect($item)
            ->route->toBe('core.terms')
            ->icon->toBe('o-calendar-days')
            ->and(route('core.terms', absolute: false))->toBe('/terms');
    });

    it('la ven el admin y la subdirección', function () {
        expect(seesTermsMenu($this->admin))->toBeTrue()
            ->and(seesTermsMenu($this->subdireccion))->toBeTrue();
    });

    it('no la ven los demás roles', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

        expect(seesTermsMenu($user))->toBeFalse();
    })->with([
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ]);
});
