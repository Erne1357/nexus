<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\DefaultScopeLabels;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Selector de periodo de la credencial (core::components.context-switcher, #415): ofrece todos los periodos y fija la
| elección con SetActiveContext. La credencial ya no elige campus: lo elige cada pantalla (`?campus=`).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    $this->current = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->previous = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    $this->jefe = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
        ->create();
});

/**
 * Manda `apply` como lo manda el navegador, con la página en Referer (Livewire::test no la manda), y da la respuesta.
 */
function applyFromPage(Testable $component, string $page): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true', 'Referer' => $page])->postJson(app('livewire')->getUpdateUri(), ['components' => [[
        'snapshot' => json_encode($component->snapshot),
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'apply', 'params' => []]],
    ]]]);
}

it('ofrece todos los periodos, del más reciente al más antiguo, y ningún campus', function (string $user) {
    $this->actingAs($user === 'jefe' ? $this->jefe : User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create());

    Livewire::test('core::components.context-switcher')
        ->assertSeeInOrder(['Periodo', 'Ene-Jun 2027', 'Ago-Dic 2026'])
        ->assertSeeHtml('wire:model="termId"')
        ->assertDontSee('Campus I')
        ->assertDontSee('Campus II')
        ->assertDontSeeHtml('name="campus"')
        ->assertSet('termId', $this->current->id);
})->with(['de dos campus' => 'jefe', 'de un campus' => 'coordinador']);

it('cambia el periodo activo con SetActiveContext y recarga la página', function () {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('termId', $this->previous->id)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertRedirect(Livewire::originalUrl());

    expect(app(Actor::class)->activeTerm()?->is($this->previous))->toBeTrue();
});

it('al recargar conserva el campus que la página eligió, y nada más de su consulta', function () {
    $this->actingAs($this->jefe);
    $component = Livewire::test('core::components.context-switcher')->set('termId', $this->previous->id);

    $redirect = applyFromPage($component, url('/scheduling/editor?grupo=abc&campus=C2'))->assertOk()->json('components.0.effects.redirect');

    expect($redirect)->toEndWith('?campus=C2')
        ->not->toContain('grupo');
});

it('sin campus en la página recarga la misma ruta sin consulta', function () {
    $this->actingAs($this->jefe);
    $component = Livewire::test('core::components.context-switcher')->set('termId', $this->previous->id);

    $redirect = applyFromPage($component, url('/scheduling/editor?grupo=abc'))->assertOk()->json('components.0.effects.redirect');

    expect($redirect)->not->toContain('?');
});

it('confirma con un toast que escapa el nombre del periodo, porque MaryUI lo pinta como HTML', function () {
    $this->previous->update(['name' => '<img src=x onerror=alert(1)>']);
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('termId', $this->previous->id)
        ->call('apply');

    expect(session('mary.toast.title'))->toBe('Periodo actualizado')
        ->and(session('mary.toast.description'))->toBe('&lt;img src=x onerror=alert(1)&gt;');
});

it('al elegir el periodo actual deja el de omisión, para seguirlo cuando cambie', function () {
    $this->actingAs($this->jefe);
    session([Actor::ACTIVE_TERM => $this->previous->id]);

    Livewire::test('core::components.context-switcher')
        ->set('termId', $this->current->id)
        ->call('apply');

    expect(session(Actor::ACTIVE_TERM))->toBeNull();
});

describe('elección rechazada', function () {
    beforeEach(function () {
        $this->actingAs($this->jefe);
        session([Actor::ACTIVE_TERM => $this->previous->id]);
    });

    it('con un periodo que no existe avisa con un toast, regresa la selección y no cambia el periodo', function (string $id) {
        $component = Livewire::test('core::components.context-switcher')
            ->set('termId', $id)
            ->call('apply')
            ->assertOk()
            ->assertNoRedirect()
            ->assertSet('termId', $this->previous->id);

        expect(lastToastOf($component))
            ->type->toBe('error')
            ->title->toBe('No se cambió el periodo')
            ->description->toBe('El periodo elegido ya no existe.')
            ->and(session(Actor::ACTIVE_TERM))->toBe($this->previous->id);
    })->with([
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
            ->assertRedirect(Livewire::originalUrl());

        expect(app(Actor::class)->activeTerm()?->is($this->{$term}))->toBeTrue();
    })->with(['current', 'previous']);

    it('con un periodo activo no ofrece la opción vacía', function () {
        $this->current->update(['is_current' => true]);

        Livewire::test('core::components.context-switcher')
            ->assertSet('termId', $this->current->id)
            ->assertDontSeeHtml('Sin periodo');
    });
});

it('sin sesión no ofrece periodos', function () {
    Livewire::test('core::components.context-switcher')
        ->assertDontSee('Ene-Jun 2027');
});

/*
| Perfil activo (spec 8.1, AZ-S4): «Actuando como», un renglón por puesto con su alcance, arriba del periodo.
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

    Livewire::test('core::components.context-switcher')->assertSee('Coordinador de carrera · IGE, ISC · Presencial (C2)');
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
        ->and(session(Actor::ACTIVE_ROLE))->toBe(Role::Coordinador->value);
});

it('rechaza un puesto que no tiene y deja el perfil como estaba', function () {
    $user = multiRoleUser($this->c2);
    actingAsRole($user, Role::JefeDepartamento);

    Livewire::test('core::components.context-switcher')
        ->set('roleValue', 'subdireccion')
        ->call('switchRole')
        ->assertNoRedirect()
        ->assertSet('roleValue', 'jefe_departamento');

    expect(session(Actor::ACTIVE_ROLE))->toBe(Role::JefeDepartamento->value);
});

it('cambiar el periodo nunca cambia el perfil, aunque el perfil elegido en el selector sea otro', function () {
    $user = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();
    actingAsRole($user, Role::JefeProyectoDocente);

    Livewire::test('core::components.context-switcher')
        ->set('roleValue', 'coordinador')
        ->set('termId', $this->previous->id)
        ->call('apply')
        ->assertRedirect(Livewire::originalUrl());

    expect(session(Actor::ACTIVE_ROLE))->toBe(Role::JefeProyectoDocente->value)
        ->and(session(Actor::ACTIVE_TERM))->toBe($this->previous->id)
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

        public function careerOptions(?string $campusId): array
        {
            return [];
        }

        public function careerNames(array $careerIds): array
        {
            return [];
        }

        public function careerGroups(?string $campusId): array
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
        ->assertSee('Coordinador de carrera · 1 carrera · Presencial (C2)');

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

    Livewire::test('core::components.context-switcher')->assertSee('Coordinador de carrera · 2 carreras · Presencial (C2)');
});
