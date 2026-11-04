<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\DefaultScopeLabels;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Livewire;

/*
| Selector de campus y periodo de la credencial (core::components.context-switcher). Ofrece los campus
| de ScopedContext::campuses() y los periodos existentes, y fija la elección con SetActiveContext.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->foreign = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus ajeno']);

    $this->current = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->previous = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    $this->jefe = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
        ->create();
});

it('ofrece solo los campus del alcance y todos los periodos, del más reciente al más antiguo', function () {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->assertSee('Campus I')
        ->assertSee('Campus II')
        ->assertDontSee('Campus ajeno')
        ->assertSeeInOrder(['Ene-Jun 2027', 'Ago-Dic 2026'])
        ->assertSet('campusId', null)
        ->assertSet('termId', $this->current->id);
});

it('con un solo campus lo muestra fijo, sin selector de campus', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create());

    Livewire::test('core::components.context-switcher')
        ->assertSee('Campus II')
        ->assertViewHas('campuses', fn ($campuses): bool => $campuses->pluck('code')->all() === ['C2'])
        ->assertDontSeeHtml('wire:model="campusId"')
        ->assertSeeHtml('wire:model="termId"')
        ->assertSet('campusId', $this->c2->id);
});

it('cambia el campus y el periodo activos con SetActiveContext', function () {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('campusId', $this->c2->id)
        ->set('termId', $this->previous->id)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertRedirect();

    $context = app(ScopedContext::class);

    expect($context->activeCampus()?->is($this->c2))->toBeTrue()
        ->and($context->activeTerm()?->is($this->previous))->toBeTrue();
});

it('confirma con un toast que escapa los nombres, porque MaryUI lo pinta como HTML', function () {
    $this->c2->update(['name' => '<img src=x onerror=alert(1)>']);
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('campusId', $this->c2->id)
        ->call('apply');

    expect(session('mary.toast.title'))->toBe('Contexto actualizado')
        ->and(session('mary.toast.description'))->toBe('&lt;img src=x onerror=alert(1)&gt; · Ene-Jun 2027');
});

it('al elegir el periodo actual deja el de omisión, para seguirlo cuando cambie', function () {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('campusId', $this->c1->id)
        ->set('termId', $this->current->id)
        ->call('apply');

    expect(session(ScopedContext::ACTIVE_CAMPUS))->toBe($this->c1->id)
        ->and(session(ScopedContext::ACTIVE_TERM))->toBeNull();
});

it('al volver a "Elige un campus" olvida el campus elegido', function () {
    $this->actingAs($this->jefe);
    session([ScopedContext::ACTIVE_CAMPUS => $this->c2->id]);

    Livewire::test('core::components.context-switcher')
        ->assertSet('campusId', $this->c2->id)
        ->set('campusId', '')
        ->call('apply')
        ->assertHasNoErrors();

    expect(session(ScopedContext::ACTIVE_CAMPUS))->toBeNull()
        ->and(app(ScopedContext::class)->activeCampus())->toBeNull();
});

it('ofrece los campus del perfil activo y rechaza el de otro de sus puestos', function () {
    // Por omisión su perfil sería Jefatura de proyecto docente (C1), el primero en el orden del enum.
    $user = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->create();
    actingAsRole($user, Role::Coordinador);

    $component = Livewire::test('core::components.context-switcher')
        ->assertViewHas('campuses', fn ($campuses): bool => $campuses->pluck('code')->all() === ['C2'])
        ->assertSet('campusId', $this->c2->id)
        ->set('campusId', $this->c1->id)
        ->call('apply')
        ->assertNoRedirect()
        ->assertSet('campusId', $this->c2->id);

    expect(lastToastOf($component))
        ->type->toBe('error')
        ->description->toBe('No trabajas en ese campus.')
        ->and(session(ScopedContext::ACTIVE_CAMPUS))->toBeNull();
});

describe('elección rechazada', function () {
    beforeEach(function () {
        $this->actingAs($this->jefe);
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id, ScopedContext::ACTIVE_TERM => $this->previous->id]);
    });

    it('con un campus fuera del alcance avisa con un toast, regresa la selección y no cambia el contexto', function () {
        $component = Livewire::test('core::components.context-switcher')
            ->set('campusId', $this->foreign->id)
            ->set('termId', $this->current->id)
            ->call('apply')
            ->assertOk()
            ->assertNoRedirect()
            ->assertSet('campusId', $this->c1->id)
            ->assertSet('termId', $this->previous->id);

        expect(lastToastOf($component))
            ->type->toBe('error')
            ->title->toBe('No se cambió el campus ni el periodo')
            ->description->toBe('No trabajas en ese campus.')
            ->and(session(ScopedContext::ACTIVE_CAMPUS))->toBe($this->c1->id)
            ->and(session(ScopedContext::ACTIVE_TERM))->toBe($this->previous->id);
    });

    it('con un campus o un periodo que no existen avisa con un toast, regresa la selección y no cambia el contexto', function (string $property, string $id) {
        $component = Livewire::test('core::components.context-switcher')
            ->set($property, $id)
            ->call('apply')
            ->assertOk()
            ->assertNoRedirect()
            ->assertSet('campusId', $this->c1->id)
            ->assertSet('termId', $this->previous->id);

        expect(lastToastOf($component))
            ->type->toBe('error')
            ->title->toBe('No se cambió el campus ni el periodo')
            ->description->toBe('El campus o el periodo elegido ya no existe.')
            ->and(session(ScopedContext::ACTIVE_CAMPUS))->toBe($this->c1->id)
            ->and(session(ScopedContext::ACTIVE_TERM))->toBe($this->previous->id);
    })->with(['campusId', 'termId'])->with([
        'uuid sin registro' => '0190a8c2-3d4e-7f00-8a00-000000000000',
        'id que no es uuid' => '999999',
    ]);
});

describe('sin periodo activo', function () {
    beforeEach(function () {
        $this->current->update(['is_current' => false]);
        $this->actingAs($this->jefe);
    });

    it('muestra una opción vacía "Sin periodo" en lugar de aparentar que el primero está activo', function () {
        Livewire::test('core::components.context-switcher')
            ->assertSet('termId', null)
            ->assertSeeHtml('<option value="">Sin periodo</option>');
    });

    it('deja elegir cualquier periodo', function (string $term) {
        Livewire::test('core::components.context-switcher')
            ->set('termId', $this->{$term}->id)
            ->call('apply')
            ->assertRedirect();

        expect(app(ScopedContext::class)->activeTerm()?->is($this->{$term}))->toBeTrue();
    })->with(['current', 'previous']);

    it('con un periodo activo no ofrece la opción vacía', function () {
        $this->current->update(['is_current' => true]);

        Livewire::test('core::components.context-switcher')
            ->assertSet('termId', $this->current->id)
            ->assertDontSeeHtml('Sin periodo');
    });
});

it('sin sesión no ofrece campus ni periodos', function () {
    Livewire::test('core::components.context-switcher')
        ->assertDontSee('Campus I')
        ->assertDontSee('Ene-Jun 2027');
});

/*
| Perfil activo (spec 8.1, AZ-S4): «Actuando como», un renglón por puesto con su alcance, arriba de campus y periodo.
*/

function multiRoleUser(Campus $campus): User
{
    return User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])
        ->withRole(Role::Docente)
        ->create();
}

it('ofrece un renglón por puesto con su alcance', function () {
    $user = multiRoleUser($this->c2);
    actingAsRole($user, Role::JefeDepartamento);

    Livewire::test('core::components.context-switcher')
        ->assertSee('Actuando como')
        ->assertSeeInOrder(['Jefe de departamento académico', 'Coordinador de carrera · ', '(C2)', 'Docente'])
        ->assertSet('roleValue', 'jefe_departamento');
});

it('con las carreras nombradas por Academic muestra sus códigos', function () {
    $career = Career::factory()->create(['code' => 'ISC']);
    $other = Career::factory()->create(['code' => 'IGE']);
    $user = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $career->id])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $other->id])
        ->withRole(Role::Docente)
        ->create();
    actingAsRole($user, Role::Docente);

    Livewire::test('core::components.context-switcher')->assertSee('Coordinador de carrera · IGE, ISC (C2)');
});

it('con un solo puesto no muestra selector de perfil', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create());

    Livewire::test('core::components.context-switcher')
        ->assertDontSee('Actuando como')
        ->assertDontSeeHtml('wire:model="roleValue"');
});

it('al elegir un perfil lo cambia y regresa a Inicio', function () {
    $user = multiRoleUser($this->c2);
    actingAsRole($user, Role::JefeDepartamento);

    Livewire::test('core::components.context-switcher')
        ->set('roleValue', 'coordinador')
        ->call('switchRole')
        ->assertRedirect(route('home'));

    expect($user->fresh()->last_role)->toBe(Role::Coordinador)
        ->and(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::Coordinador->value);
});

it('rechaza un puesto que no tiene y deja el perfil como estaba', function () {
    $user = multiRoleUser($this->c2);
    actingAsRole($user, Role::JefeDepartamento);

    Livewire::test('core::components.context-switcher')
        ->set('roleValue', 'subdireccion')
        ->call('switchRole')
        ->assertNoRedirect()
        ->assertSet('roleValue', 'jefe_departamento');

    expect(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::JefeDepartamento->value);
});

it('cambiar el campus nunca cambia el perfil, aunque el perfil elegido en el selector sea otro', function () {
    $user = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();
    actingAsRole($user, Role::JefeProyectoDocente);

    Livewire::test('core::components.context-switcher')
        ->set('roleValue', 'coordinador')
        ->set('campusId', $this->c1->id)
        ->call('apply')
        ->assertRedirect(Livewire::originalUrl());

    expect(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::JefeProyectoDocente->value)
        ->and(session(ScopedContext::ACTIVE_CAMPUS))->toBe($this->c1->id)
        ->and($user->fresh()->last_role)->toBeNull();
});

it('arma los renglones de perfil una vez al montar, no en cada pintado', function () {
    $labels = new class implements ScopeLabels
    {
        public int $calls = 0;

        public function careers(array $careerIds): array
        {
            $this->calls++;

            return [];
        }

        public function careerOptions(string $campusId): array
        {
            return [];
        }
    };
    app()->instance(ScopeLabels::class, $labels);
    actingAsRole(multiRoleUser($this->c2), Role::JefeDepartamento);

    Livewire::test('core::components.context-switcher')
        ->assertSee('Actuando como')
        ->call('$refresh')
        ->assertSee('Actuando como')
        ->assertSee('Coordinador de carrera · 1 carrera (C2)');

    expect($labels->calls)->toBe(1);
});

it('sin quien nombre las carreras, el resumen cuenta cuántas son', function () {
    app()->bind(ScopeLabels::class, DefaultScopeLabels::class);
    $user = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(21)])
        ->withRole(Role::Docente)
        ->create();
    actingAsRole($user, Role::Docente);

    Livewire::test('core::components.context-switcher')->assertSee('Coordinador de carrera · 2 carreras (C2)');
});
