<?php

use App\Modules\Core\Actions\AssignPermissionSet;
use App\Modules\Core\Actions\CreatePermissionSet;
use App\Modules\Core\Actions\GrantPositionPermission;
use App\Modules\Core\Actions\UpdatePermissionSet;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\PermissionSetsQuery;
use App\Modules\Core\Support\ActivityProtectedProperties;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| AZ-03 (confirmado el 20 de noviembre de 2026, #738): el directivo también consulta «Personas y puestos» (la lista y la
| ficha), el Organigrama y la Bitácora, de solo lectura y de todo el Tec. Es la excepción de fábrica de CF-03: esas tres
| lecturas siguen siendo candados del panel, que no las da a ningún otro puesto. Nombrar, terminar, dar de alta, las
| acciones de la cuenta y editar el organigrama siguen siendo solo del admin: el directivo no los ve y, si los llama
| directo, recibe 403 sin que se escriba nada.
*/

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->seed(OrgChartSeeder::class);

    $this->planning = OrgUnit::where('code', 'SUB-PLANEACION')->sole();
    $this->services = OrgUnit::where('code', 'SERVICIOS-ESCOLARES')->sole();

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->directivo = User::factory()->withRole(Role::Directivo, ['org_unit_id' => $this->planning->id])->create(['name' => 'Diana Directiva', 'username' => 'diana']);
    $this->head = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id, 'valid_from' => '2026-01-01'])->create(['name' => 'Héctor Escolar', 'username' => '19110002']);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Sara Subdirectora', 'username' => '19110003']);

    activity()->performedOn($this->services)->causedBy($this->admin)->event('updated')->log('Renombró Servicios Escolares');
    $this->activityBase = (int) Activity::max('id');
});

/**
 * Las tres lecturas que el directivo tiene de fábrica entre los candados (CF-03).
 *
 * @return list<CorePermission>
 */
function directivoLockedReads(): array
{
    return [CorePermission::AssignmentView, CorePermission::OrgUnitView, CorePermission::ActivityView];
}

describe('AZ-03: de fábrica', function () {
    it('AZ-03: entre los candados, el directivo tiene de fábrica solo ver Personas y puestos, el Organigrama y la Bitácora', function () {
        $registry = app(PermissionRegistry::class);
        $granted = [];

        foreach ($registry->permissions() as $permission) {
            if ($registry->isAdminOnly($permission) && $registry->rolesGranting($permission) !== []) {
                $granted[$permission->value] = array_map(fn (Role $role): string => $role->value, $registry->rolesGranting($permission));
            }
        }

        ksort($granted);

        expect($granted)->toBe([
            'core.activity.view' => ['directivo'],
            'core.assignment.view' => ['directivo'],
            'core.org_unit.view' => ['directivo'],
        ]);

        foreach (directivoLockedReads() as $permission) {
            expect($registry->isAdminOnly($permission))->toBeTrue()
                ->and(app(GrantTable::class)->grants(Role::Directivo, $permission))->toBeTrue();
        }
    });

    it('AZ-03: su menú muestra Personas y puestos, Organigrama y Bitácora, y no Puestos y permisos ni Correos y colas', function () {
        $this->actingAs($this->directivo);
        $menu = array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));

        expect($menu)->toContain('Personas y puestos', 'Organigrama', 'Bitácora')
            ->not->toContain('Puestos y permisos', 'Correos y colas', 'Avisos y atajos', 'Parámetros');
    });
});

describe('CF-03: el panel sigue sin darlas', function () {
    it('CF-03: el panel no da esas lecturas a ningún otro puesto, ni sueltas ni en un rol', function (Role $role) {
        $rules = app(PermissionGrantRules::class);

        foreach (directivoLockedReads() as $permission) {
            expect($rules->grantProblem($role, $permission))->toBe("«{$permission->label()}» es solo del admin y, de fábrica, de Directivo: no se da a otro puesto.")
                ->and($rules->grantProblem(Role::Directivo, $permission))->toBeNull();
        }

        $this->actingAs($this->admin);

        expect(fn () => app(GrantPositionPermission::class)->handle($role, CorePermission::ActivityView))->toThrow(ValidationException::class)
            ->and(fn () => app(CreatePermissionSet::class)->handle('Consulta', null, [CorePermission::OrgUnitView->value]))->toThrow(ValidationException::class)
            ->and(fn () => app(AssignPermissionSet::class)->handle($role, PermissionSet::query()->where('factory_role', Role::Directivo->value)->sole()))->toThrow(ValidationException::class);

        DB::table('position_permissions')->insert(['role' => $role->value, 'permission' => CorePermission::AssignmentView->value]);
        app()->forgetScopedInstances();

        expect(app(GrantTable::class)->grants($role, CorePermission::AssignmentView))->toBeFalse();
    })->with([Role::Subdireccion, Role::JefeDepartamento, Role::JefeProyectoDocente, Role::Coordinador]);

    it('CF-03: «¿Quién puede…?» dice que esas lecturas son del admin y del directivo; los demás candados, solo del admin', function () {
        $badge = fn (string $key): string => textOf(domOf(Livewire::withQueryParams(['vista' => 'quien', 'permiso' => $key])->actingAs($this->admin)->test('core::pages.permissions'))
            ->querySelector('[data-testid="who-result"] [data-testid="lock-badge"]'));

        expect($badge(CorePermission::ActivityView->value))->toBe('Solo del admin y Directivo')
            ->and($badge(CorePermission::OrgUnitView->value))->toBe('Solo del admin y Directivo')
            ->and($badge(CorePermission::AuthzView->value))->toBe('Solo del admin');
    });

    it('CF-02: editar el rol de fábrica del directivo conserva sus lecturas de candado', function () {
        $this->actingAs($this->admin);
        $set = PermissionSet::query()->where('factory_role', Role::Directivo->value)->sole();
        $keys = array_values(array_diff(app(PermissionRegistry::class)->matrix()[Role::Directivo->value], [CorePermission::TermView->value]));

        app(UpdatePermissionSet::class)->handle($set, ['permissions' => $keys]);
        app()->forgetScopedInstances();

        expect($set->fresh()->follows_factory)->toBeFalse()
            ->and(app(GrantTable::class)->grants(Role::Directivo, CorePermission::ActivityView))->toBeTrue()
            ->and(app(GrantTable::class)->grants(Role::Directivo, CorePermission::TermView))->toBeFalse();
    });

    it('CF-03: quitada del rol de fábrica del directivo, el panel se la puede devolver a él, en su rol o suelta', function () {
        $this->actingAs($this->admin);
        $set = PermissionSet::query()->where('factory_role', Role::Directivo->value)->sole();
        $keys = array_values(array_diff(app(PermissionRegistry::class)->matrix()[Role::Directivo->value], [CorePermission::ActivityView->value]));

        app(UpdatePermissionSet::class)->handle($set, ['permissions' => $keys]);
        app()->forgetScopedInstances();

        expect(app(GrantTable::class)->grants(Role::Directivo, CorePermission::ActivityView))->toBeFalse()
            ->and(app(PermissionSetsQuery::class)->addProblems($set->id))->not->toHaveKey(CorePermission::ActivityView->value)
            ->and(app(PermissionSetsQuery::class)->addProblems($set->id))->toHaveKey(CorePermission::AuthzView->value)
            ->and(app(PermissionSetsQuery::class)->addProblems(null))->toHaveKey(CorePermission::ActivityView->value);

        app(GrantPositionPermission::class)->handle(Role::Directivo, CorePermission::ActivityView);
        app()->forgetScopedInstances();

        expect(app(GrantTable::class)->grants(Role::Directivo, CorePermission::ActivityView))->toBeTrue()
            ->and(fn () => app(GrantPositionPermission::class)->handle(Role::Directivo, CorePermission::AuthzView))->toThrow(ValidationException::class);
    });
});

describe('Personas y puestos', function () {
    it('el directivo abre la lista y la ficha de una persona, con sus puestos', function () {
        $this->actingAs($this->directivo)->get(route('core.people'))->assertOk()->assertSee(['Héctor Escolar', 'Sara Subdirectora', 'Ada Admin']);
        $this->actingAs($this->directivo)->get(route('core.people.show', $this->head->id))->assertOk()->assertSee(['Héctor Escolar', 'Servicios Escolares']);
    });

    it('la lista no le muestra «Dar de alta» y llamar a sus métodos da 403 sin escribir', function () {
        Livewire::actingAs($this->admin)->test('core::pages.people')->assertSeeHtml('data-testid="create-user"');

        Livewire::actingAs($this->directivo)->test('core::pages.people')
            ->assertSee('Héctor Escolar')
            ->assertDontSeeHtml('data-testid="create-user"')
            ->call('openCreate')->assertForbidden();

        $page = Livewire::actingAs($this->admin)->test('core::pages.people')->call('openCreate')
            ->set('newUsername', 'nuevo01')->set('newName', 'Nadia Nueva');

        $this->actingAs($this->directivo);
        $page->call('createUser')->assertForbidden();

        expect(User::where('username', 'nuevo01')->exists())->toBeFalse();
    });

    it('la ficha no le muestra «Nombrar», «Terminar…» ni las acciones de la cuenta', function () {
        $assignment = $this->head->roleAssignments()->sole();

        Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->head->id])
            ->assertSeeHtml(['data-testid="appoint"', 'data-testid="end-'.$assignment->id.'"', 'data-testid="account-edit"']);

        Livewire::actingAs($this->directivo)->test('core::pages.person', ['user' => $this->head->id])
            ->assertSee('Héctor Escolar')
            ->assertSeeHtml('data-testid="assignment-'.$assignment->id.'"')
            ->assertDontSeeHtml(['data-testid="appoint"', 'data-testid="end-'.$assignment->id.'"', 'data-testid="account-menu"', 'data-testid="account-edit"', 'data-testid="account-deactivate"', 'data-testid="appoint-drawer"', 'data-testid="deactivate-drawer"']);
    });

    it('cada método de la ficha que escribe le da 403 y no escribe', function (string $method) {
        $assignment = $this->head->roleAssignments()->sole();
        $page = Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->head->id]);

        match ($method) {
            'appoint' => $page->call('openAppoint')->set('role', Role::Admin->value),
            'end' => $page->call('confirmEnd', $assignment->id),
            'saveProfile' => $page->call('openProfile')->set('profileName', 'Otro Nombre'),
            'deactivate' => $page->call('openDeactivate')->set('deactivationReason', 'otro'),
            default => null,
        };

        $this->actingAs($this->directivo);

        match ($method) {
            'confirmEnd' => $page->call('confirmEnd', $assignment->id)->assertForbidden(),
            'toggleDepartment' => $page->call('toggleDepartment', $this->services->id)->assertForbidden(),
            default => $page->call($method)->assertForbidden(),
        };

        expect($assignment->fresh()->valid_to)->toBeNull()
            ->and($this->head->fresh())->name->toBe('Héctor Escolar')->is_active->toBeTrue()
            ->and($this->head->roleAssignments()->count())->toBe(1)
            ->and(activitySince()->count())->toBe(0);
    })->with(['openAppoint', 'appoint', 'selectAllInCampus', 'selectAllInModality', 'toggleDepartment', 'confirmEnd', 'end', 'openProfile', 'saveProfile', 'sendPasswordReset', 'invite', 'openDeactivate', 'deactivate', 'reactivate']);
});

describe('Organigrama', function () {
    it('el directivo abre el organigrama y el panel de una unidad, con su titular', function () {
        $this->actingAs($this->directivo)->get(route('core.org-chart'))->assertOk()->assertSee(['Servicios Escolares', 'Subdirección de Planeación y Vinculación']);

        Livewire::actingAs($this->directivo)->test('core::components.org-unit-panel')
            ->dispatch('org-unit-picked', id: $this->services->id)
            ->assertSee(['Servicios Escolares', 'Héctor Escolar']);
    });

    it('no le muestra «Nueva unidad», «Nombrar», «Terminar…» ni las acciones de la unidad', function () {
        $assignment = $this->head->roleAssignments()->sole();
        $actions = ['data-testid="unit-appoint"', 'data-testid="end-'.$assignment->id.'"', 'data-testid="unit-rename"', 'data-testid="unit-move"', 'data-testid="unit-add-child"', 'data-testid="unit-deactivate"'];

        $this->actingAs($this->admin)->get(route('core.org-chart'))->assertSee('data-testid="org-new-unit"', false);
        Livewire::actingAs($this->admin)->test('core::components.org-unit-panel')->dispatch('org-unit-picked', id: $this->services->id)->assertSeeHtml($actions);

        $this->actingAs($this->directivo)->get(route('core.org-chart'))->assertDontSee('data-testid="org-new-unit"', false);
        Livewire::actingAs($this->directivo)->test('core::components.org-unit-panel')
            ->dispatch('org-unit-picked', id: $this->services->id)
            ->assertSee('Héctor Escolar')
            ->assertDontSeeHtml([...$actions, 'id="unit-actions-title"']);
    });

    it('cada método del panel que escribe le da 403 y no escribe', function (string $method) {
        $assignment = $this->head->roleAssignments()->sole();
        $panel = Livewire::actingAs($this->admin)->test('core::components.org-unit-panel')->dispatch('org-unit-picked', id: $this->services->id);

        match ($method) {
            'save' => $panel->call('openCreate', $this->planning->id)->set('name', 'Oficina de Becas')->set('code', 'BECAS')->set('kind', 'oficina'),
            'move' => $panel->call('openMove')->set('parent', $this->planning->id),
            'appoint' => $panel->call('openAppoint')->call('choosePerson', $this->directivo->id),
            'end' => $panel->call('confirmEnd', $assignment->id),
            default => null,
        };

        $this->actingAs($this->directivo);

        match ($method) {
            'openCreate' => $panel->call('openCreate', $this->planning->id)->assertForbidden(),
            'setActive' => $panel->call('setActive', false)->assertForbidden(),
            'choosePerson' => $panel->call('choosePerson', $this->directivo->id)->assertForbidden(),
            'confirmEnd' => $panel->call('confirmEnd', $assignment->id)->assertForbidden(),
            default => $panel->call($method)->assertForbidden(),
        };

        expect(OrgUnit::where('code', 'BECAS')->exists())->toBeFalse()
            ->and($assignment->fresh()->valid_to)->toBeNull()
            ->and($this->services->fresh())->is_active->toBeTrue()
            ->and($this->directivo->roleAssignments()->count())->toBe(1)
            ->and(activitySince()->count())->toBe(0);
    })->with(['openCreate', 'openEdit', 'openMove', 'save', 'move', 'setActive', 'openAppoint', 'choosePerson', 'appoint', 'confirmEnd', 'end']);
});

describe('Bitácora', function () {
    it('el directivo ve la bitácora de todo el Tec y abre el detalle de una entrada sin escribir nada', function () {
        $entry = Activity::query()->where('description', 'Renombró Servicios Escolares')->sole();

        $this->actingAs($this->directivo)->get(route('core.activity'))->assertOk()->assertSee('Renombró Servicios Escolares');

        Livewire::actingAs($this->directivo)->test('core::pages.activity')
            ->assertSee('Renombró Servicios Escolares')
            ->call('openEntry', $entry->id)
            ->assertSet('showDetail', true)
            ->assertSet('entryId', $entry->id);

        expect(activitySince()->count())->toBe(0);
    });
});

describe('Bitácora: lo reservado', function () {
    beforeEach(function () {
        $this->teacher = Teacher::factory()->create(['rfc' => 'TODO800101AAA', 'name' => 'Tomás Docente']);
        $this->discharged = activity()->performedOn($this->teacher)->causedBy($this->admin)->event('discharged')
            ->withProperties(['review_id' => null, 'department_id' => null, 'deactivated' => true, 'reason' => ['kind' => 'other', 'text' => 'Motivo reservado D8']])
            ->log('Quitó al docente TODO800101AAA de Sistemas y Computación en la validación de docentes');
        $this->deactivated = activity()->performedOn($this->subdireccion)->causedBy($this->admin)->event('deactivated')
            ->withProperties(['reason' => 'renuncia', 'note' => 'Nota reservada de la baja', 'ended_assignment_ids' => [], 'removed_assignment_ids' => []])
            ->log('Dio de baja la cuenta de 19110003 (Renuncia)');
    });

    it('D8: el directivo ve la salida de un docente (quién, a quién, cuándo) sin su motivo', function () {
        Livewire::actingAs($this->directivo)->test('core::pages.activity')
            ->assertSee('Quitó al docente TODO800101AAA de Sistemas y Computación')
            ->call('openEntry', $this->discharged->id)
            ->assertViewHas('detail', fn ($detail): bool => ! array_key_exists('reason', $detail->properties) && $detail->properties['deactivated'] === true)
            ->assertSee('Ada Admin')
            ->assertDontSee('Motivo reservado D8');
    });

    it('CF-05: el directivo ve el motivo de la baja de una cuenta, no su nota', function () {
        Livewire::actingAs($this->directivo)->test('core::pages.activity')
            ->call('openEntry', $this->deactivated->id)
            ->assertViewHas('detail', fn ($detail): bool => $detail->properties['reason'] === 'renuncia' && ! array_key_exists('note', $detail->properties))
            ->assertSee('renuncia')
            ->assertDontSee('Nota reservada de la baja');
    });

    it('el admin sigue viendo el motivo D8 y la nota de la baja', function () {
        Livewire::actingAs($this->admin)->test('core::pages.activity')
            ->call('openEntry', $this->discharged->id)
            ->assertSee('Motivo reservado D8')
            ->call('openEntry', $this->deactivated->id)
            ->assertSee('Nota reservada de la baja');
    });

    it('D8: el motivo de salida lo ven quien da de baja docentes (admin y Subdirección); la nota de la baja de una cuenta, solo el admin', function () {
        $visible = function (User $user, string $event, array $properties): array {
            $this->actingAs($user);
            app()->forgetScopedInstances();

            return app(ActivityProtectedProperties::class)->visibleTo(app(Actor::class), $event, $properties);
        };
        $reason = ['reason' => ['kind' => 'other', 'text' => 'Motivo reservado D8'], 'deactivated' => true];
        $account = ['reason' => 'renuncia', 'note' => 'Nota reservada de la baja'];

        expect($visible($this->subdireccion, 'discharged', $reason))->toBe($reason)
            ->and($visible($this->admin, 'discharged', $reason))->toBe($reason)
            ->and($visible($this->directivo, 'discharged', $reason))->toBe(['deactivated' => true])
            ->and($visible($this->admin, 'deactivated', $account))->toBe($account)
            ->and($visible($this->subdireccion, 'deactivated', $account))->toBe(['reason' => 'renuncia'])
            ->and($visible($this->directivo, 'deactivated', $account))->toBe(['reason' => 'renuncia'])
            ->and($visible($this->directivo, 'updated', $account))->toBe($account);
    });
});

describe('alcance', function () {
    it('los demás puestos siguen sin ver Personas y puestos, el Organigrama ni la Bitácora', function (Role $role) {
        $user = match ($role) {
            Role::Subdireccion => $this->subdireccion,
            Role::JefeDepartamento => $this->head,
        };

        $this->actingAs($user)->get(route('core.people'))->assertForbidden();
        $this->actingAs($user)->get(route('core.people.show', $this->directivo->id))->assertForbidden();
        $this->actingAs($user)->get(route('core.org-chart'))->assertForbidden();
        $this->actingAs($user)->get(route('core.activity'))->assertForbidden();
    })->with([Role::Subdireccion, Role::JefeDepartamento]);
});
