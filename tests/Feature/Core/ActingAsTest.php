<?php

use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
| La bitácora registra con qué perfil actuó la persona en sesión (spec 8.6): `acting_as`.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2']);
    $this->ana = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])
        ->create();
});

it('cada entrada de la persona en sesión lleva el perfil con que actúa', function () {
    actingAsRole($this->ana, Role::Coordinador);

    activity()->causedBy($this->ana)->log('x');
    activity()->log('y');

    expect(Activity::query()->orderBy('id')->get()->map(fn ($a) => $a->properties['acting_as'])->all())
        ->toBe(['coordinador', 'coordinador']);
});

it('acting_as va al final sin tocar las demás propiedades', function () {
    actingAsRole($this->ana, Role::Coordinador);

    activity()->withProperties(['a' => 1])->log('x');

    expect(Activity::sole()->properties->all())->toBe(['a' => 1, 'acting_as' => 'coordinador']);
});

it('tras cambiar de perfil, la entrada del cambio lleva el perfil anterior y las siguientes el nuevo', function () {
    actingAsRole($this->ana, Role::JefeDepartamento);

    app(SwitchRole::class)->handle(Role::Coordinador);
    activity()->log('despues');

    $entries = Activity::query()->orderBy('id')->get();

    expect($entries->first()->properties['acting_as'])->toBe('jefe_departamento')
        ->and($entries->last()->properties['acting_as'])->toBe('coordinador');
});

it('una entrada causada por otra persona, sin sesión o sin causante no lleva acting_as', function () {
    $beto = User::factory()->admin()->create();

    actingAsRole($this->ana, Role::Coordinador);
    activity()->causedBy($beto)->log('de beto');
    $porOtra = Activity::query()->latest('id')->first();

    auth()->logout();
    activity()->causedBy($beto)->log('sin sesion');
    $sinSesion = Activity::query()->latest('id')->first();

    activity()->causedByAnonymous()->log('sin causante');
    $sinCausante = Activity::query()->latest('id')->first();

    expect($porOtra->properties->has('acting_as'))->toBeFalse()
        ->and($sinSesion->properties->has('acting_as'))->toBeFalse()
        ->and($sinCausante->properties->has('acting_as'))->toBeFalse();
});
