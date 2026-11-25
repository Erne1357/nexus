<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\UpdateOrgUnit;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RoleTitleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| CO-01 y CO-02 (C10 y C12, confirmados el 24 de noviembre de 2026): el puesto «Personal de área» (`personal`) se nombra
| sobre una unidad que no es departamento académico, sin titular único. En Comunicación y Difusión, la jefa y su
| personal manejan los avisos y los atajos de la pantalla de acceso y las fotos del personal; solo la jefa nombra a su
| personal (`core.unit_staff.appoint`). Los permisos aplican a la unidad COMUNICACION (AZ-02, AZ-S16).
*/

beforeEach(function () {
    $this->unit = communicationUnit();
    $this->hr = OrgUnit::factory()->create(['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento]);
});

/**
 * Lo que el puesto puede hacer con el permiso, sin objetivo (el menú y la entrada a la pantalla).
 *
 * @return array<string, bool>
 */
function communicationPermissionsOf(User $user, Role $role): array
{
    test()->actingAs($user);
    $context = app(Actor::class)->forUser($user)->asRole($role);

    return collect([
        CorePermission::LoginNoticeView,
        CorePermission::LoginNoticeManage,
        CorePermission::LoginShortcutView,
        CorePermission::LoginShortcutManage,
        CorePermission::StaffPhotoManage,
        CorePermission::UnitStaffAppoint,
    ])->mapWithKeys(fn (CorePermission $permission): array => [$permission->value => $context->allowsAny($permission)])->all();
}

it('el personal de área se nombra sobre una unidad no académica y su credencial dice "Personal del Departamento de Comunicación y Difusión"', function () {
    $this->actingAs(User::factory()->admin()->create());
    $rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $beto = User::factory()->create(['name' => 'Beto Rentería']);

    $assignment = app(AppointRole::class)->handle($rosa, Role::Personal, null, $this->unit, null, CarbonImmutable::today());
    app(AppointRole::class)->handle($beto, Role::Personal, null, $this->unit, null, CarbonImmutable::today());

    expect($assignment->fresh())->role->toBe(Role::Personal)->org_unit_id->toBe($this->unit->id)
        ->and(RoleAssignment::query()->where('role', Role::Personal)->where('org_unit_id', $this->unit->id)->count())->toBe(2)
        ->and(Role::Personal->listLabel())->toBe('Personal de área');

    $this->actingAs($rosa);

    expect(app(RoleTitleQuery::class)->of(app(Actor::class), Role::Personal)['title'])->toBe('Personal del Departamento de Comunicación y Difusión');

    $credential = domOf($this->get('/resumen'))->querySelector('[data-testid="credential"]');

    expect(textOf($credential))->toContain('Personal del Departamento de Comunicación y Difusión');
});

it('no se nombra sobre un departamento académico', function () {
    $this->actingAs(User::factory()->admin()->create());
    $rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $systems = OrgUnit::query()->findOrFail(Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación'])->id);

    expect(fn () => app(AppointRole::class)->handle($rosa, Role::Personal, null, $systems, null, CarbonImmutable::today()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Sistemas y Computación es un departamento académico: el personal de área va sobre una unidad que no lo es.']))
        ->and(fn () => app(AppointRole::class)->handle($rosa, Role::Personal, null, OrgUnit::query()->findOrFail($this->unit->parent_id), null, CarbonImmutable::today()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Subdirección de Planeación y Vinculación no es un departamento, una división ni una oficina.']))
        ->and(fn () => app(AppointRole::class)->handle($rosa, Role::Personal, null, null, null, CarbonImmutable::today()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Elige la unidad.']))
        ->and(fn () => DB::transaction(fn () => DB::table('role_assignments')->insert(['user_id' => $rosa->id, 'role' => Role::Personal->value, 'valid_from' => today()->toDateString()])))
        ->toThrow(QueryException::class, 'role_assignments_scope_complete');

    expect(RoleAssignment::query()->where('user_id', $rosa->id)->exists())->toBeFalse();
});

it('CO-01: el jefe y el personal de Comunicación tienen avisos, atajos y fotos del personal; el jefe de otro departamento no administrativo no', function () {
    $head = communicationHead(['name' => 'Carla Comunicación']);
    $staff = communicationStaff(['name' => 'Pablo Personal']);
    $hrHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->hr->id])->create();
    $hrStaff = User::factory()->withRole(Role::Personal, ['org_unit_id' => $this->hr->id])->create();

    expect(communicationPermissionsOf($head, Role::JefeDepartamento))->toBe([
        'core.login_notice.view' => true,
        'core.login_notice.manage' => true,
        'core.login_shortcut.view' => true,
        'core.login_shortcut.manage' => true,
        'core.staff_photo.manage' => true,
        'core.unit_staff.appoint' => true,
    ])
        ->and(communicationPermissionsOf($staff, Role::Personal))->toBe([
            'core.login_notice.view' => true,
            'core.login_notice.manage' => true,
            'core.login_shortcut.view' => true,
            'core.login_shortcut.manage' => true,
            'core.staff_photo.manage' => true,
            'core.unit_staff.appoint' => false,
        ])
        ->and(array_filter(communicationPermissionsOf($hrHead, Role::JefeDepartamento)))->toBe([])
        ->and(array_filter(communicationPermissionsOf($hrStaff, Role::Personal)))->toBe([]);

    $this->actingAs($head)->get(route('core.login-content'))->assertOk();
    $this->actingAs($staff)->get(route('core.login-content'))->assertOk();
    $this->actingAs($hrHead)->get(route('core.login-content'))->assertForbidden();
    $this->actingAs($hrStaff)->get(route('core.login-content'))->assertForbidden();
});

it('CO-01: la jefa y el personal de Comunicación dan de alta y editan avisos y atajos desde «Avisos y atajos»', function (string $who) {
    Storage::fake('public');
    $user = $who === 'jefa' ? communicationHead() : communicationStaff();

    $page = Livewire::actingAs($user)->test('core::pages.login-content')
        ->call('create')->set('title', "Aviso de la {$who}")->call('save')->assertHasNoErrors()
        ->set('tab', 'shortcuts')
        ->call('create')->set('title', "Atajo de la {$who}")->set('url', 'https://www.tecnm.mx')->call('save')->assertHasNoErrors();

    $notice = LoginNotice::query()->where('title', "Aviso de la {$who}")->sole();
    $shortcut = LoginShortcut::query()->where('title', "Atajo de la {$who}")->sole();

    $page->call('edit', $shortcut->id)->set('title', "Atajo editado de la {$who}")->call('save')->assertHasNoErrors()
        ->set('tab', 'notices')
        ->call('edit', $notice->id)->set('title', "Aviso editado de la {$who}")->call('save')->assertHasNoErrors();

    expect($notice->fresh()->title)->toBe("Aviso editado de la {$who}")
        ->and($shortcut->fresh()->title)->toBe("Atajo editado de la {$who}");
})->with(['jefa', 'personal']);

it('CO-01: el Inicio de la jefa de Comunicación ya no dice "Tu área todavía no tiene funciones en Nexus."', function () {
    $this->actingAs(communicationHead());

    Livewire::test('core::pages.home')
        ->assertSeeInOrder(['Jefe del Departamento de Comunicación y Difusión', 'Manejas lo que se ve al entrar a Nexus, las fotos y las credenciales.'])
        ->assertDontSee('Tu área todavía no tiene funciones en Nexus.');

    $this->actingAs(communicationStaff());

    Livewire::test('core::pages.home')
        ->assertSeeInOrder(['Personal del Departamento de Comunicación y Difusión', 'Manejas lo que se ve al entrar a Nexus, las fotos y las credenciales.'])
        ->assertDontSee('Tu área todavía no tiene funciones en Nexus.');

    $this->actingAs(User::factory()->withRole(Role::Personal, ['org_unit_id' => $this->hr->id])->create());

    Livewire::test('core::pages.home')
        ->assertSeeInOrder(['Personal del Departamento de Recursos Humanos', 'Tu área todavía no tiene funciones en Nexus.']);
});

it('la clave COMUNICACION no cambia mientras la unidad tenga jefe o personal', function () {
    $staff = RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::Personal, 'org_unit_id' => $this->unit->id, 'valid_from' => today()->addWeek()]);
    $message = 'Nexus usa la clave COMUNICACION para el jefe y el personal de Comunicación y Difusión; termina sus nombramientos antes de cambiarla.';

    expect(fn () => app(UpdateOrgUnit::class)->handle($this->unit, ['code' => 'DIFUSION']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['code'])->toBe([$message]));

    $staff->delete();
    $head = RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $this->unit->id]);

    expect(fn () => app(UpdateOrgUnit::class)->handle($this->unit->fresh(), ['code' => 'DIFUSION']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['code'])->toBe([$message]));

    $head->update(['valid_from' => today()->subMonth(), 'valid_to' => today()->subDay()]);
    app(UpdateOrgUnit::class)->handle($this->unit->fresh(), ['code' => 'DIFUSION']);

    expect($this->unit->fresh()->code)->toBe('DIFUSION')
        ->and(app(OrgTree::class)->idOf('DIFUSION'))->not->toBeNull();
});
