<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Livewire;

/*
| Selector de campus y periodo de la topbar (core::components.context-switcher). Ofrece los campus
| de ScopedContext::campuses() y los periodos existentes, y fija la elección con SetActiveContext.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->foreign = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus ajeno']);

    $this->current = Term::factory()->current()->create(['code' => '2027-1', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->previous = Term::factory()->create(['code' => '2026-2', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    $this->jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => 10])
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => 10])
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
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => 20])->create());

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

it('rechaza un campus fuera del alcance y no cambia el contexto', function () {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set('campusId', $this->foreign->id)
        ->call('apply')
        ->assertForbidden();

    expect(session()->has(ScopedContext::ACTIVE_CAMPUS))->toBeFalse()
        ->and(app(ScopedContext::class)->activeCampus())->toBeNull();
});

it('rechaza un campus o un periodo que no existen', function (string $property) {
    $this->actingAs($this->jefe);

    Livewire::test('core::components.context-switcher')
        ->set($property, 999_999)
        ->call('apply')
        ->assertNotFound();

    expect(session()->has(ScopedContext::ACTIVE_CAMPUS))->toBeFalse();
})->with(['campusId', 'termId']);

it('sin sesión no ofrece campus ni periodos', function () {
    Livewire::test('core::components.context-switcher')
        ->assertDontSee('Campus I')
        ->assertDontSee('Ene-Jun 2027');
});
