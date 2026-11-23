<?php

use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\RoleAccess;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Settings\SettingRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| AC-21 (tarea 1.29, pedido del usuario del 22 de noviembre de 2026): en «Parámetros» el admin decide qué perfiles inician
| sesión (`core.role_access`, todos encendidos por omisión). Un puesto apagado no cuenta para nada: quien solo tiene ese no
| entra, quien tiene varios solo usa los encendidos, y una sesión abierta que se queda sin ninguno sale en su siguiente
| petición. El admin nunca se apaga y solo él cambia el parámetro (candado `core.access.manage`).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * El admin cambia el parámetro con la Action de la pantalla: `$off` son los campos que apaga; los demás quedan encendidos.
 *
 * @param  list<string>  $off
 */
function setRoleAccessOff(array $off): void
{
    $table = array_fill_keys(array_keys(RoleAccess::fields()), true);

    foreach ($off as $field) {
        $table[$field] = false;
    }

    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(RoleAccess::SETTING), $table);
}

function roleAccessLogin(User $user): TestResponse
{
    return test()->from('/login')->post('/login', ['username' => $user->username, 'password' => 'password']);
}

describe('el parámetro', function () {
    it('AC-21: lista cada puesto menos el admin y al personal sin puesto, todos encendidos por omisión', function () {
        $definition = app(SettingRegistry::class)->get(RoleAccess::SETTING);

        expect(array_keys($definition->fields))->toBe(['subdireccion', 'directivo', 'jefe_departamento', 'jefe_proyecto_docente', 'coordinador', 'docente', 'alumno', 'sin_puesto'])
            ->and($definition->default)->each->toBeTrue()
            ->and($definition->permission)->toBe(CorePermission::AccessManage)
            ->and($definition->permissionOnly)->toBeTrue();
    });

    it('AC-21: no acepta un campo del admin, así que el admin no se puede apagar', function () {
        $definition = app(SettingRegistry::class)->get(RoleAccess::SETTING);

        expect($definition->accepts([...$definition->default, 'admin' => false]))->toBeFalse()
            ->and($definition->accepts($definition->default))->toBeTrue();
    });

    it('AC-21: `core.access.manage` es candado: no se le da a ningún otro puesto', function () {
        $giving = new class(app()) extends ModuleServiceProvider
        {
            public function grants(): array
            {
                return [Role::Subdireccion->value => [CorePermission::AccessManage]];
            }
        };

        expect(fn () => PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $giving]))->toThrow(LogicException::class);
    });

    it('AC-21: el admin apaga un perfil desde la pantalla y queda en la bitácora', function () {
        Livewire::actingAs($this->admin)->test('core::pages.settings')
            ->assertSet('values.core__role_access.docente', true)
            ->set('values.core__role_access.docente', false)
            ->call('save', 'core')
            ->assertHasNoErrors();

        $stored = Setting::query()->where('scope', SettingScope::Global)->where('key', RoleAccess::SETTING)->sole()->value;
        $log = Activity::query()->where('description', 'like', 'Cambió el parámetro «Quién inicia sesión»%')->sole();

        expect($stored['docente'])->toBeFalse()
            ->and($stored['alumno'])->toBeTrue()
            ->and($log->causer?->is($this->admin))->toBeTrue()
            ->and(app(RoleAccess::class)->allows(Role::Docente))->toBeFalse();
    });

    it('AC-21: Subdirección lo ve en solo lectura y, aunque tenga «cambiar los parámetros», guardar le responde 403', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $extra = new class(app()) extends ModuleServiceProvider
        {
            public function grants(): array
            {
                return [Role::Subdireccion->value => [CorePermission::SettingUpdate]];
            }
        };
        app()->instance(PermissionRegistry::class, PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $extra]));
        app()->forgetScopedInstances();

        Livewire::actingAs($subdireccion)->test('core::pages.settings')
            ->set('values.core__role_access.docente', false)
            ->call('save', 'core')
            ->assertForbidden();

        expect(Setting::query()->where('key', RoleAccess::SETTING)->exists())->toBeFalse();
    });
});

describe('el login', function () {
    it('AC-21: quien solo tiene un perfil apagado no entra y se le dice, aunque su contraseña sea correcta', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();
        setRoleAccessOff(['docente']);

        roleAccessLogin($docente)->assertRedirect('/login')->assertSessionHasErrors(['username' => RoleAccess::DENIED_MESSAGE]);

        $this->assertGuest();
    });

    it('AC-21: con su perfil encendido entra como siempre', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();
        setRoleAccessOff(['alumno']);

        roleAccessLogin($docente)->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($docente);
    });

    it('AC-21: el admin entra aunque todo lo demás esté apagado', function () {
        setRoleAccessOff(array_keys(RoleAccess::fields()));

        roleAccessLogin($this->admin)->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($this->admin);
    });

    it('AC-21: el personal sin puesto entra solo con su interruptor encendido', function () {
        $staff = User::factory()->create();

        roleAccessLogin($staff)->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($staff);
        Auth::logout();

        setRoleAccessOff(['sin_puesto']);

        roleAccessLogin($staff)->assertSessionHasErrors(['username' => RoleAccess::DENIED_MESSAGE]);
        $this->assertGuest();
    });

    it('AC-21: quien tiene varios perfiles entra y solo actúa con los encendidos: el apagado no se ofrece ni se puede elegir', function () {
        $user = User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])
            ->create(['last_role' => Role::Coordinador]);
        setRoleAccessOff(['coordinador']);

        roleAccessLogin($user)->assertSessionHasNoErrors();

        $context = app(Actor::class);

        expect($context->heldRoles())->toBe([Role::Docente])
            ->and($context->activeRole())->toBe(Role::Docente)
            ->and($context->heldScopes(Role::Coordinador))->toBeEmpty()
            ->and(fn () => app(SwitchRole::class)->handle(Role::Coordinador))->toThrow(AuthorizationException::class);
    });
});

describe('la sesión abierta', function () {
    it('AC-21: si apagan su único perfil, en la siguiente petición sale al login con el aviso', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($docente)->get('/')->assertOk();

        setRoleAccessOff(['docente']);

        $this->get('/')->assertRedirect(route('login'))->assertSessionHasErrors(['username' => RoleAccess::DENIED_MESSAGE]);
        $this->assertGuest();
    });

    it('AC-21: a una acción de Livewire le responde 401 en lugar de redirigir', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();
        setRoleAccessOff(['docente']);

        $this->actingAs($docente)->get('/', ['X-Livewire' => 'true'])->assertUnauthorized()->assertJson(['message' => RoleAccess::DENIED_MESSAGE]);
    });

    it('AC-21: si apagan su perfil activo y le queda otro, sigue con ese', function () {
        $user = User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])
            ->create();

        $this->actingAs($user)->withSession([Actor::ACTIVE_ROLE => Role::Coordinador->value])->get('/')->assertOk();
        expect(app(Actor::class)->activeRole())->toBe(Role::Coordinador);

        setRoleAccessOff(['coordinador']);

        $this->get('/')->assertOk();
        $this->assertAuthenticatedAs($user);
        expect(app(Actor::class)->activeRole())->toBe(Role::Docente)
            ->and(app(Actor::class)->allowsAny(CorePermission::TermView))->toBe(app(Actor::class)->asRole(Role::Docente)->allowsAny(CorePermission::TermView));
    });
});
