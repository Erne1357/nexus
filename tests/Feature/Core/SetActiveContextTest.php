<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\RecentPages;
use Illuminate\Auth\Access\AuthorizationException;

/*
| Periodo de la credencial (#415: la credencial ya no elige campus; el campus lo elige cada pantalla).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->current = Term::factory()->current()->create();
    $this->previous = Term::factory()->create();

    $this->user = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
        ->create();
});

it('fija el periodo activo del usuario', function () {
    $this->actingAs($this->user);

    app(SetActiveContext::class)->handle($this->previous);

    expect(app(Actor::class)->activeTerm()?->is($this->previous))->toBeTrue()
        ->and(session(Actor::ACTIVE_TERM))->toBe($this->previous->id);
});

it('con null olvida la elección y vuelve al periodo actual', function () {
    $this->actingAs($this->user);
    app(SetActiveContext::class)->handle($this->previous);

    app(SetActiveContext::class)->handle(null);

    expect(app(Actor::class)->activeTerm()?->is($this->current))->toBeTrue()
        ->and(session(Actor::ACTIVE_TERM))->toBeNull();
});

it('no guarda ningún campus en la sesión', function () {
    $this->actingAs($this->user);

    app(SetActiveContext::class)->handle($this->previous);

    expect(array_filter(array_keys(session()->all()), fn (string $key): bool => str_contains($key, 'campus')))->toBe([])
        ->and(app(Actor::class)->activeCampus())->toBeNull();
});

it('olvida las páginas del menú que el usuario visitó: una ficha de otro periodo ya no aplicaría', function () {
    $this->actingAs(User::factory()->admin()->create());
    app(RecentPages::class)->remember('core.campuses', '/campus?search=norte');

    app(SetActiveContext::class)->handle($this->previous);

    expect(app(RecentPages::class)->last(NavigationArea::Academico))->toBeNull();
});

it('rechaza fijar el periodo sin usuario autenticado', function () {
    expect(fn () => app(SetActiveContext::class)->handle($this->previous))
        ->toThrow(AuthorizationException::class);
});
