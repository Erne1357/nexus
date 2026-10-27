<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->current = Term::factory()->current()->create();
    $this->previous = Term::factory()->create();

    $this->user = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => 10])
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => 10])
        ->create();
});

it('fija el campus y el periodo activos del usuario', function () {
    $this->actingAs($this->user);

    app(SetActiveContext::class)->handle($this->c2, $this->previous);

    $context = app(ScopedContext::class);

    expect($context->activeCampus()?->is($this->c2))->toBeTrue()
        ->and($context->activeTerm()?->is($this->previous))->toBeTrue();
});

it('con null olvida la elección y vuelve al campus y periodo por omisión', function () {
    $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
    app(SetActiveContext::class)->handle($this->c2, $this->previous);

    app(SetActiveContext::class)->handle(null, null);

    $context = app(ScopedContext::class);

    expect($context->activeCampus()?->is($this->c2))->toBeTrue()
        ->and($context->activeTerm()?->is($this->current))->toBeTrue();
});

it('rechaza un campus fuera del alcance del usuario y no cambia el contexto', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => 20])->create());
    $other = Campus::factory()->create(['code' => 'C9']);

    expect(fn () => app(SetActiveContext::class)->handle($other, $this->previous))
        ->toThrow(AuthorizationException::class);

    $context = app(ScopedContext::class);

    expect($context->activeCampus()?->is($this->c2))->toBeTrue()
        ->and($context->activeTerm()?->is($this->current))->toBeTrue();
});

it('rechaza un campus inactivo, también para el admin', function () {
    $this->actingAs(User::factory()->admin()->create());
    $inactive = Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);

    expect(fn () => app(SetActiveContext::class)->handle($inactive, null))
        ->toThrow(AuthorizationException::class);
});

it('rechaza fijar contexto sin usuario autenticado', function () {
    expect(fn () => app(SetActiveContext::class)->handle($this->c2, null))
        ->toThrow(AuthorizationException::class);
});

it('deja de usar el campus elegido cuando el alcance sobre él vence', function () {
    $this->actingAs($this->user);
    app(SetActiveContext::class)->handle($this->c1, null);

    RoleScope::where('user_id', $this->user->id)->where('campus_id', $this->c1->id)->update(['valid_to' => today()->subDay()]);
    app()->forgetScopedInstances();

    expect(app(ScopedContext::class)->activeCampus()?->is($this->c2))->toBeTrue();
});
