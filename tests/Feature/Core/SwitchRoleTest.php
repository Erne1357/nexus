<?php

use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/*
| Cambio de perfil (spec 8.1, AZ-S4): la sesión pasa a actuar con un solo puesto, que se recuerda para la siguiente.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->user = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();
});

it('cambia el perfil, lo recuerda para la siguiente sesión y deja bitácora', function () {
    actingAsRole($this->user, Role::JefeDepartamento);

    app(SwitchRole::class)->handle(Role::Coordinador);

    $log = Activity::latest('id')->first();

    expect(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::Coordinador->value)
        ->and(app(ScopedContext::class)->activeRole())->toBe(Role::Coordinador)
        ->and($this->user->fresh()->last_role)->toBe(Role::Coordinador)
        ->and($log->description)->toBe('Cambió al perfil Coordinador de carrera')
        ->and($log->subject->is($this->user))->toBeTrue()
        ->and($log->properties->all())->toBe(['from' => 'jefe_departamento', 'to' => 'coordinador', 'acting_as' => 'jefe_departamento']);
});

it('rechaza un puesto que no tiene', function () {
    actingAsRole($this->user, Role::JefeDepartamento);

    expect(fn () => app(SwitchRole::class)->handle(Role::Subdireccion))
        ->toThrow(AuthorizationException::class, 'No tienes ese puesto.')
        ->and(app(ScopedContext::class)->activeRole())->toBe(Role::JefeDepartamento);
});

it('olvida el campus si el nuevo perfil no trabaja en él', function () {
    actingAsRole($this->user, Role::JefeDepartamento);
    session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

    app(SwitchRole::class)->handle(Role::Coordinador);

    expect(session()->has(ScopedContext::ACTIVE_CAMPUS))->toBeFalse();

    actingAsRole($this->user, Role::Coordinador);
    session([ScopedContext::ACTIVE_CAMPUS => $this->c2->id]);
    app(SwitchRole::class)->handle(Role::JefeDepartamento);

    expect(session(ScopedContext::ACTIVE_CAMPUS))->toBe($this->c2->id);
});

it('olvida las páginas recientes', function () {
    actingAsRole($this->user, Role::JefeDepartamento);
    session(['core.recent_pages' => ['academico' => 'core.campuses']]);

    app(SwitchRole::class)->handle(Role::Coordinador);

    expect(session()->has('core.recent_pages'))->toBeFalse();
});

it('si la bitácora falla, no cambia el perfil de la sesión, ni last_role, ni olvida las páginas recientes', function () {
    actingAsRole($this->user, Role::JefeDepartamento);
    session(['core.recent_pages' => ['academico' => '/campus']]);
    Activity::creating(function (): void {
        throw new RuntimeException('No se pudo escribir la bitácora.');
    });

    expect(fn () => app(SwitchRole::class)->handle(Role::Coordinador))->toThrow(RuntimeException::class, 'No se pudo escribir la bitácora.')
        ->and(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::JefeDepartamento->value)
        ->and($this->user->fresh()->last_role)->toBeNull()
        ->and(session('core.recent_pages'))->toBe(['academico' => '/campus']);
});
