<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\PermissionCatalogQuery;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Puestos y permisos» (`/configuracion/puestos-y-permisos`, core::pages.permissions; 1.22-B, maqueta B «por puesto»):
| las pestañas Puestos, Roles y ¿Quién puede…?; los drawers «Agregar rol», «Agregar permiso suelto» y «Editar rol»; y
| las confirmaciones cortas. Cada cambio llama a la Action del motor (CF-01, CF-02, CF-S2) y sus bloqueos salen con el
| motivo de las reglas: el admin no se edita (CF-S1), los candados (CF-03) y lo que ampliaría el alcance (CF-04). Solo
| el admin entra (core.authz.*, candados); cada otro puesto tiene su prueba negativa, en la página y en cada acción.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function permissionsPage(array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs(test()->admin)->test('core::pages.permissions');
}

function factorySetOf(Role $role): PermissionSet
{
    return PermissionSet::query()->where('factory_role', $role)->sole();
}

/**
 * @return list<string>
 */
function setNamesOfPosition(Role $role): array
{
    return DB::table('position_permission_sets')
        ->join('permission_sets', 'permission_sets.id', '=', 'position_permission_sets.permission_set_id')
        ->where('role', $role->value)
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

/**
 * @return list<string>
 */
function loosePermissionsOf(Role $role): array
{
    return DB::table('position_permissions')->where('role', $role->value)->orderBy('permission')->pluck('permission')->all();
}

function grantProblemFor(Role $role, string $key): string
{
    return app(PermissionGrantRules::class)->grantProblem($role, app(PermissionRegistry::class)->forKey($key));
}

/**
 * El rol de fábrica del jefe editado, sin `$missing`, con `$missing` como nuevo de fábrica (CF-02).
 */
function jefeFactoryWithNew(string $missing): PermissionSet
{
    $set = factorySetOf(Role::JefeDepartamento);
    $keys = array_values(array_diff(app(PermissionRegistry::class)->matrix()['jefe_departamento'], [$missing]));
    $set->forceFill(['follows_factory' => false, 'factory_baseline' => $keys])->save();
    DB::table('permission_set_permissions')->insert(array_map(fn (string $key): array => ['permission_set_id' => $set->id, 'permission' => $key], $keys));
    app()->forgetScopedInstances();

    return $set;
}

function nonAdminHolder(Role $role): User
{
    $campus = Campus::factory()->create();

    return User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['department_id' => departmentKey(10)],
        Role::JefeProyectoDocente => ['campus_id' => $campus->id, 'department_id' => departmentKey(10)],
        default => [],
    })->create();
}

describe('Puestos', function () {
    it('lista los nueve puestos con sus personas, roles, sueltos y permisos, y abre el primero que se edita', function () {
        $html = $this->actingAs($this->admin)->get(route('core.permissions'))->assertOk();
        $dom = domOf($html);
        $cards = $dom->querySelectorAll('[data-testid="position-card"]');
        $admin = app(PermissionRegistry::class);
        $exceptions = count($admin->adminDenials());

        expect(array_map(fn ($card): string => $card->getAttribute('data-position'), iterator_to_array($cards)))->toBe(array_map(fn (Role $role): string => $role->value, Role::cases()))
            ->and(textOf($cards->item(0)))->toContain('Administración', "Todo menos {$exceptions} excepciones", '1')
            ->and(textOf($cards->item(2)))->toContain('Directivo', '1 rol · 0 sueltos · '.count($admin->matrix()['directivo']).' permisos')
            ->and(textOf($cards->item(5)))->toContain('Coordinador de carrera', '1 rol · 0 sueltos · '.count($admin->matrix()['coordinador']).' permisos')
            ->and(textOf($cards->item(6)))->toContain('Personal de área', '1 rol · 0 sueltos · '.count($admin->matrix()['personal']).' permisos')
            ->and($cards->item(1)->getAttribute('aria-current'))->toBe('true')
            ->and(textOf($dom->querySelector('[data-testid="position-detail"] h2')))->toBe('Subdirección Académica')
            ->and(textOf($dom->querySelector('[data-testid="position-detail"]')))->toContain('Su unidad; todo el Tec en campus y modalidad');
    });

    it('recuerda el puesto elegido en la URL y muestra sus roles, sus sueltos y sus permisos efectivos con su origen', function () {
        PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador, Role::JefeProyectoDocente)->create(['name' => 'Parámetros']);
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);

        $page = permissionsPage(['puesto' => 'coordinador'])->assertSet('position', 'coordinador');
        $detail = domOf($page)->querySelector('[data-testid="position-detail"]');

        expect(textOf($detail->querySelector('h2')))->toBe('Coordinador de carrera')
            ->and(textOf($detail->querySelector('[data-testid="position-sets"]')))->toContain('Coordinador de carrera (de fábrica)', 'Sigue al código', 'Parámetros', '1 permiso · también Jefe de proyecto docente')
            ->and(textOf($detail->querySelector('[data-testid="position-loose"]')))->toContain('Ver periodos', 'core.term.view')
            ->and(textOf($detail->querySelector('[data-testid="effective-row-core.term.view"]')))->toContain('Suelto')
            ->and(textOf($detail->querySelector('[data-testid="effective-row-core.setting.view"]')))->toContain('Parámetros');

        $page->call('$set', 'position', 'docente')->assertSet('position', 'docente');
    });

    it('busca en los permisos efectivos y deja solo los que no vienen de fábrica', function () {
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);

        $page = permissionsPage(['puesto' => 'coordinador'])->set('outsideFactory', true);
        $rows = domOf($page)->querySelectorAll('[data-testid^="effective-row-"]');

        expect(count($rows))->toBe(1)
            ->and($rows->item(0)->getAttribute('data-testid'))->toBe('effective-row-core.term.view');

        $page->set('outsideFactory', false)->set('effectiveSearch', 'PERIODOS');

        expect(array_map(fn ($row): string => $row->getAttribute('data-testid'), iterator_to_array(domOf($page)->querySelectorAll('[data-testid^="effective-row-"]'))))->toBe(['effective-row-core.term.view']);
    });

    it('CF-S1: Administración sale de solo lectura: todo menos sus excepciones, que se listan', function () {
        $registry = app(PermissionRegistry::class);
        $detail = domOf(permissionsPage(['puesto' => 'admin']))->querySelector('[data-testid="position-detail"]');
        $declared = count($registry->permissions());
        $denied = count($registry->adminDenials());

        expect(textOf($detail))->toContain('No se edita', ($declared - $denied)." de {$declared} permisos", 'Excepciones · '.$denied, StaffingPermission::MyLoadView->label(), 'staffing.my_load.view')
            ->and($detail->querySelector('[data-testid="add-role"]'))->toBeNull()
            ->and($detail->querySelector('[data-testid="add-loose"]'))->toBeNull();
    });

    it('CF-S1: el admin no se edita aunque se llame directo al componente', function (string $method, array $arguments) {
        $page = permissionsPage(['puesto' => 'admin']);

        $page->set('addRoleId', factorySetOf(Role::Docente)->id)
            ->set('looseKeys', [CorePermission::SettingView->value])
            ->call($method, ...$arguments)
            ->assertHasErrors();

        expect(DB::table('position_permission_sets')->where('role', 'admin')->exists())->toBeFalse()
            ->and(DB::table('position_permissions')->where('role', 'admin')->exists())->toBeFalse();
    })->with([
        'agregar rol' => ['assignRole', []],
        'dar sueltos' => ['grantLoose', []],
        'restablecer puesto' => ['restorePosition', []],
    ]);

    it('CF-01: agrega un rol al puesto desde el drawer, con el aviso', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Parámetros']);

        $page = permissionsPage(['puesto' => 'coordinador'])
            ->call('openAddRole')
            ->assertSet('showAddRole', true)
            ->set('addRoleId', $set->id)
            ->call('assignRole')
            ->assertHasNoErrors()
            ->assertSet('showAddRole', false);

        expect(setNamesOfPosition(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)', 'Parámetros'])
            ->and(lastToastOf($page)['title'])->toBe('Coordinador de carrera tiene el rol «Parámetros»');
    });

    it('CF-04: en «Agregar rol» el rol que ampliaría el alcance sale deshabilitado con su motivo, y no se da aunque se fuerce', function () {
        $blocked = PermissionSet::factory()->withPermissions([FacultyPermission::TeacherView])->create(['name' => 'Docentes']);
        $problem = grantProblemFor(Role::Coordinador, 'faculty.teacher.view');

        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddRole');
        $option = domOf($page)->querySelector('[data-testid="add-role-option-'.$blocked->id.'"]');

        expect($option->querySelector('input')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($option))->toContain($problem)
            ->and(textOf(domOf($page)->querySelector('[data-testid="add-role-option-'.factorySetOf(Role::Coordinador)->id.'"]')))->toContain('Ya lo tiene.');

        $page->set('addRoleId', $blocked->id)->call('assignRole')->assertHasErrors(['permission_set']);

        expect(setNamesOfPosition(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)']);
    });

    it('CF-03: un rol con un candado no se le da a un puesto', function () {
        $locked = PermissionSet::factory()->withPermissions([CorePermission::UserCreate])->create(['name' => 'Usuarios']);

        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddRole')->set('addRoleId', $locked->id)->call('assignRole')->assertHasErrors(['permission_set']);

        expect($page->errors()->first('permission_set'))->toBe(app(PermissionGrantRules::class)->lockProblem(CorePermission::UserCreate))
            ->and(setNamesOfPosition(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)']);
    });

    it('CF-01: quita un rol del puesto, con el aviso', function () {
        $page = permissionsPage(['puesto' => 'docente'])->call('unassignRole', factorySetOf(Role::Docente)->id)->assertHasNoErrors();

        expect(setNamesOfPosition(Role::Docente))->toBe([])
            ->and(lastToastOf($page)['title'])->toBe('Rol quitado');
    });

    it('CF-01: da varios permisos sueltos desde el drawer, y los que ya tiene salen marcados y deshabilitados', function () {
        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->assertSet('showAddLoose', true);
        $factoryKey = app(PermissionRegistry::class)->matrix()['coordinador'][0];
        $row = domOf($page)->querySelector('[data-testid="loose-row-'.$factoryKey.'"]');

        expect($row->querySelector('input')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($row))->toContain('Ya lo tiene: Rol de fábrica');

        $page->set('looseKeys', [CorePermission::SettingView->value, CorePermission::TermView->value])
            ->call('grantLoose')
            ->assertHasNoErrors()
            ->assertSet('showAddLoose', false);

        expect(loosePermissionsOf(Role::Coordinador))->toBe(['core.setting.view', 'core.term.view'])
            ->and(lastToastOf($page)['title'])->toBe('2 permisos sueltos para Coordinador de carrera');
    });

    it('CF-03 y CF-04: en «Agregar permiso suelto» los candados y lo que ampliaría el alcance salen deshabilitados con su motivo, y no se dan aunque se fuercen', function () {
        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->set('pickerSearch', 'docentes');
        $blocked = domOf($page)->querySelector('[data-testid="loose-row-faculty.teacher.view"]');

        expect($blocked->querySelector('input')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($blocked))->toContain(grantProblemFor(Role::Coordinador, 'faculty.teacher.view'));

        $page->set('pickerSearch', 'core.user.create');

        expect(textOf(domOf($page)->querySelector('[data-testid="loose-row-core.user.create"]')))->toContain(app(PermissionGrantRules::class)->lockProblem(CorePermission::UserCreate));

        $page->set('looseKeys', [CorePermission::SettingView->value, FacultyPermission::TeacherView->value, CorePermission::UserCreate->value])
            ->call('grantLoose')
            ->assertHasErrors(['permissions']);

        expect(loosePermissionsOf(Role::Coordinador))->toBe([]);
    });

    it('marca y desmarca de un jalón los permisos que se pueden dar de un grupo', function () {
        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->call('toggleGroup', 'loose', 'core_accounts');

        // Los avisos del login declaran la unidad (CO-01): al coordinador, que no la lleva, no se le dan (CF-04).
        expect($page->get('looseKeys'))->toContain('core.setting.view')->not->toContain('core.user.create')->not->toContain('core.login_notice.view');

        $page->call('toggleGroup', 'loose', 'core_accounts');

        expect($page->get('looseKeys'))->toBe([]);
    });

    it('el aviso escapa el nombre del rol: MaryUI pinta el título con x-html', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => '<b>Negritas</b>']);

        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddRole')->set('addRoleId', $set->id)->call('assignRole')->assertHasNoErrors();

        expect(lastToastOf($page)['title'])->toBe('Coordinador de carrera tiene el rol «&lt;b&gt;Negritas&lt;/b&gt;»');

        $page->call('setRoleActive', $set->id, false);

        expect(lastToastOf($page)['description'])->toBe('«&lt;b&gt;Negritas&lt;/b&gt;»');
    });

    it('quita un permiso suelto cuya clave ya no declara ningún módulo, y lo marca en la lista', function () {
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => 'core.cosa.vieja']);

        $page = permissionsPage(['puesto' => 'coordinador']);

        expect(textOf(domOf($page)->querySelector('[data-testid="position-loose"]')))->toContain('core.cosa.vieja', 'Ningún módulo lo declara ya: no aporta nada.');

        $page->call('revokeLoose', 'core.cosa.vieja')->assertHasNoErrors();

        expect(loosePermissionsOf(Role::Coordinador))->toBe([])
            ->and(Activity::query()->latest('id')->first()->description)->toBe('Quitó el permiso «core.cosa.vieja» del puesto Coordinador de carrera');
    });

    it('un arreglo manipulado de casillas no rompe la pantalla: se rechaza al usarlo', function () {
        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->set('looseKeys', [['x'], 'core.setting.view'])->assertOk();

        $page->call('grantLoose')->assertHasErrors(['looseKeys.0']);
        $page->call('toggleGroup', 'loose', 'core_accounts')->assertHasErrors(['looseKeys.0']);

        $editor = permissionsPage()->call('openNewRole')->set('permissions', [['x'], 'core.term.view'])->assertOk()->assertSee('1 marcado');

        $editor->call('toggleGroup', 'editor', 'core_institution')->assertHasErrors(['permissions.0']);
        $editor->set('name', 'Otro')->call('saveRole')->assertHasErrors(['permissions.0']);

        expect(loosePermissionsOf(Role::Coordinador))->toBe([])
            ->and(PermissionSet::query()->where('name', 'Otro')->exists())->toBeFalse();
    });

    it('CF-01: quita un permiso suelto', function () {
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);

        $page = permissionsPage(['puesto' => 'coordinador'])->call('revokeLoose', 'core.term.view')->assertHasNoErrors();

        expect(loosePermissionsOf(Role::Coordinador))->toBe([])
            ->and(lastToastOf($page)['title'])->toBe('Permiso suelto quitado');
    });

    it('CF-02: «Restablecer puesto» lo deja solo con su rol de fábrica y sin sueltos', function () {
        PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create(['name' => 'Parámetros']);
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);

        $page = permissionsPage(['puesto' => 'coordinador'])->call('restorePosition')->assertHasNoErrors();

        expect(setNamesOfPosition(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)'])
            ->and(loosePermissionsOf(Role::Coordinador))->toBe([])
            ->and(lastToastOf($page)['title'])->toBe('Puesto restablecido');
    });

    it('CF-02: avisa los permisos nuevos de fábrica del puesto y los aplica', function () {
        $missing = app(PermissionRegistry::class)->matrix()['jefe_departamento'][0];
        $set = jefeFactoryWithNew($missing);

        $page = permissionsPage(['puesto' => 'jefe_departamento']);
        $dom = domOf($page);

        expect(textOf($dom->querySelector('[data-position="jefe_departamento"]')))->toContain('1 nuevo de fábrica')
            ->and(textOf($dom->querySelector('[data-testid="new-factory"]')))->toContain('1 permiso nuevo de fábrica', app(PermissionRegistry::class)->forKey($missing)->label());

        $page->call('applyFactoryUpdates', $set->id)->assertHasNoErrors();

        expect(DB::table('permission_set_permissions')->where('permission_set_id', $set->id)->where('permission', $missing)->exists())->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Permisos nuevos aplicados');
    });
});

describe('Valdrá en todo el Tec', function () {
    it('«Agregar permiso suelto» avisa lo que valdría en todo el Tec, y no lo que el puesto acota', function () {
        $page = permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->set('pickerSearch', 'core.setting.view');
        $wide = domOf($page)->querySelector('[data-testid="loose-row-core.setting.view"]');

        expect(in_array(Role::Coordinador, collect(app(PermissionCatalogQuery::class)->all())->firstWhere('key', 'core.setting.view')['institution_wide_for'], true))->toBeTrue()
            ->and(textOf($wide->querySelector('[data-testid="wide-notice"]')))->toBe('Valdrá en todo el Tec.');

        $page->set('pickerSearch', 'core.campus.view');

        expect(domOf($page)->querySelector('[data-testid="loose-row-core.campus.view"] [data-testid="wide-notice"]'))->toBeNull();
    });

    it('«Agregar rol» avisa qué de lo que trae el rol valdría en todo el Tec', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView, CorePermission::CampusView])->create(['name' => 'Parámetros']);

        $option = domOf(permissionsPage(['puesto' => 'coordinador'])->call('openAddRole'))->querySelector('[data-testid="add-role-option-'.$set->id.'"]');

        expect(textOf($option->querySelector('[data-testid="wide-notice"]')))->toBe('Valdrá en todo el Tec: «Ver los parámetros».');
    });

    it('el editor avisa lo que valdría en todo el Tec para los puestos que tienen el rol', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::CampusView])->assignedTo(Role::Coordinador)->create(['name' => 'Consulta']);

        $page = permissionsPage()->call('editRole', $set->id)->set('pickerMarked', false)->set('pickerSearch', 'core.setting.view');

        expect(textOf(domOf($page)->querySelector('[data-testid="editor-row-core.setting.view"] [data-testid="wide-notice"]')))->toBe('Valdrá en todo el Tec para Coordinador de carrera.');
    });

    it('«¿Quién puede…?» avisa junto a «Dar» si le valdría en todo el Tec', function () {
        $result = domOf(permissionsPage(['vista' => 'quien', 'permiso' => 'core.setting.view']))->querySelector('[data-testid="who-result"]');

        expect(textOf($result->querySelector('[data-testid="who-missing-coordinador"] [data-testid="wide-notice"]')))->toBe('Valdrá en todo el Tec.');
    });
});

describe('Editar rol', function () {
    it('CF-S2: da de alta un rol con nombre, descripción y permisos', function () {
        $page = permissionsPage(['vista' => 'roles'])
            ->call('openNewRole')
            ->assertSet('showEditor', true)
            ->set('name', 'Consulta de periodos')
            ->set('description', 'Ver periodos sin editarlos.')
            ->set('permissions', [CorePermission::TermView->value])
            ->call('saveRole')
            ->assertHasNoErrors()
            ->assertSet('showEditor', false);

        $set = PermissionSet::query()->where('name', 'Consulta de periodos')->sole();

        expect($set->description)->toBe('Ver periodos sin editarlos.')
            ->and(DB::table('permission_set_permissions')->where('permission_set_id', $set->id)->pluck('permission')->all())->toBe(['core.term.view'])
            ->and(lastToastOf($page)['title'])->toBe('Rol creado');
    });

    it('CF-S2: muestra junto al campo los errores del rol: sin nombre, nombre repetido y sin permisos', function () {
        PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Consulta']);

        permissionsPage()->call('openNewRole')->call('saveRole')->assertHasErrors(['name']);
        permissionsPage()->call('openNewRole')->set('name', 'CONSULTA')->set('permissions', ['core.term.view'])->call('saveRole')->assertHasErrors(['name' => ['Ya hay un rol que se llama «CONSULTA».']]);
        permissionsPage()->call('openNewRole')->set('name', 'Otro')->call('saveRole')->assertHasErrors(['permissions' => ['Elige al menos un permiso.']]);

        expect(PermissionSet::query()->whereNull('factory_role')->count())->toBe(1);
    });

    it('CF-03: el candado sale deshabilitado con su motivo en el editor y no entra aunque se fuerce', function () {
        $page = permissionsPage()->call('openNewRole')->set('pickerSearch', 'core.user.create');
        $row = domOf($page)->querySelector('[data-testid="editor-row-core.user.create"]');

        expect($row->querySelector('input')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($row))->toContain(app(PermissionGrantRules::class)->lockProblem(CorePermission::UserCreate));

        $page->set('name', 'Usuarios')->set('permissions', ['core.user.create'])->call('saveRole')->assertHasErrors(['permissions']);

        expect(PermissionSet::query()->where('name', 'Usuarios')->exists())->toBeFalse();
    });

    it('CF-02: editar los permisos de un rol de fábrica lo deja de seguir al código; «Restablecer» lo regresa', function () {
        $set = factorySetOf(Role::Docente);
        $keys = app(PermissionRegistry::class)->matrix()['docente'];

        $page = permissionsPage(['puesto' => 'docente'])->call('editRole', $set->id)
            ->assertSet('showEditor', true)
            ->assertSet('permissions', $keys)
            ->assertSet('pickerMarked', true)
            ->assertSee('Sigue al código')
            ->set('permissions', array_slice($keys, 1))
            ->assertSee('Al guardar deja de seguir al código.')
            ->call('saveRole')
            ->assertHasNoErrors();

        expect($set->fresh()->follows_factory)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Rol guardado');

        $page->call('restoreRole', $set->id)->assertHasNoErrors();

        expect($set->fresh()->follows_factory)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Rol restablecido');
    });

    it('CF-S2: en un rol que ya tiene un puesto, lo que ese puesto no acota sale deshabilitado con su motivo', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create(['name' => 'Parámetros']);

        $page = permissionsPage()->call('editRole', $set->id)->set('pickerMarked', false)->set('pickerSearch', 'faculty.teacher.view');
        $row = domOf($page)->querySelector('[data-testid="editor-row-faculty.teacher.view"]');

        expect($row->querySelector('input')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($row))->toContain(grantProblemFor(Role::Coordinador, 'faculty.teacher.view'));

        $page->set('permissions', ['core.setting.view', 'faculty.teacher.view'])->call('saveRole')->assertHasErrors(['permissions']);
    });

    it('CF-02: «Aplicar» desde el editor agrega los nuevos de fábrica', function () {
        $missing = app(PermissionRegistry::class)->matrix()['jefe_departamento'][0];
        $set = jefeFactoryWithNew($missing);

        $page = permissionsPage(['vista' => 'roles'])->call('editRole', $set->id)->assertSee('1 permiso nuevo de fábrica')->call('applyFactoryUpdates', $set->id);

        expect($page->get('permissions'))->toContain($missing);
    });
});

it('si borran el rol mientras el editor está abierto, cierra el editor con un aviso y no guarda nada', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Efímero']);
    $page = permissionsPage(['vista' => 'roles'])->call('editRole', $set->id)->set('name', 'Renombrado');

    $set->delete();

    $page->call('saveRole')->assertHasNoErrors()->assertSet('showEditor', false);

    expect(lastToastOf($page)['title'])->toBe('El rol ya no existe')
        ->and(PermissionSet::query()->whereIn('name', ['Renombrado', 'Efímero'])->exists())->toBeFalse();

    $other = PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Otro efímero']);
    $page->call('editRole', $other->id);
    $other->delete();

    $page->call('$refresh')->assertSet('showEditor', false)->assertDontSee('Nuevo rol</h2>', false);
});

describe('Roles', function () {
    it('lista los roles con sus permisos, quién los tiene y su estado, y busca por nombre y tipo', function () {
        PermissionSet::factory()->withPermissions([CorePermission::TermView])->assignedTo(Role::Coordinador)->create(['name' => 'Consulta de catálogos']);
        PermissionSet::factory()->inactive()->withPermissions([CorePermission::TermView])->create(['name' => 'Piloto']);

        $page = permissionsPage(['vista' => 'roles']);
        $dom = domOf($page);

        expect(count($dom->querySelectorAll('table tbody tr')))->toBe(10)
            ->and(textOf($dom->querySelector('[data-testid="catalog-toolbar"] .btn-primary')))->toBe('Nuevo rol')
            ->and(textOf($dom->querySelector('[data-testid="role-row-Docente (de fábrica)"]')))->toContain('Sigue al código', 'Docente')
            ->and(textOf($dom->querySelector('[data-testid="role-row-Consulta de catálogos"]')))->toContain('Activo', 'Coordinador de carrera')
            ->and(textOf($dom->querySelector('[data-testid="role-row-Piloto"]')))->toContain('Inactivo', 'Ninguno');

        $page->set('rolesSearch', 'catalogos');

        expect(count(domOf($page)->querySelectorAll('table tbody tr')))->toBe(1);

        $page->set('rolesSearch', '')->set('rolesKind', 'own');

        expect(count(domOf($page)->querySelectorAll('table tbody tr')))->toBe(2);
    });

    it('CF-S2: desactiva y activa un rol propio; uno de fábrica no se desactiva', function () {
        $set = PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Consulta']);

        $page = permissionsPage(['vista' => 'roles'])->call('setRoleActive', $set->id, false)->assertHasNoErrors();

        expect($set->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Rol desactivado');

        $page->call('setRoleActive', $set->id, true);

        expect($set->fresh()->is_active)->toBeTrue();

        $page->call('setRoleActive', factorySetOf(Role::Docente)->id, false)->assertHasErrors(['permission_set']);

        expect($page->errors()->first('permission_set'))->toBe('«Docente (de fábrica)» es un rol de fábrica: no se desactiva.')
            ->and(lastToastOf($page)['type'])->toBe('error')
            ->and(factorySetOf(Role::Docente)->is_active)->toBeTrue();
    });

    it('CF-S2: borra un rol propio que nadie tiene; uno de fábrica o asignado no se borra, y el menú dice por qué', function () {
        $free = PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Suelto']);
        $held = PermissionSet::factory()->withPermissions([CorePermission::TermView])->assignedTo(Role::Coordinador)->create(['name' => 'Asignado']);

        $page = permissionsPage(['vista' => 'roles']);
        $dom = domOf($page);

        expect(textOf($dom->querySelector('[data-testid="role-row-Docente (de fábrica)"]')))->toContain('Los roles de fábrica no se borran.', 'Los roles de fábrica no se desactivan.')
            ->and(textOf($dom->querySelector('[data-testid="role-row-Asignado"]')))->toContain('Lo tiene un puesto: quítaselo primero.');

        $page->call('deleteRole', $free->id)->assertHasNoErrors();

        expect($free->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Rol borrado');

        $page->call('deleteRole', factorySetOf(Role::Docente)->id)->assertHasErrors(['permission_set']);

        expect($page->errors()->first('permission_set'))->toBe('«Docente (de fábrica)» es un rol de fábrica: no se borra.');

        $page->call('deleteRole', $held->id)->assertHasErrors(['permission_set']);

        expect(factorySetOf(Role::Docente)->trashed())->toBeFalse()
            ->and($held->fresh()->trashed())->toBeFalse();
    });
});

describe('¿Quién puede…?', function () {
    it('dice qué puestos tienen el permiso, por qué y cuántas personas, y por qué no lo tienen los demás', function () {
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);
        nonAdminHolder(Role::Coordinador);

        $dom = domOf(permissionsPage(['vista' => 'quien', 'permiso' => 'core.setting.view']));
        $result = $dom->querySelector('[data-testid="who-result"]');

        expect(textOf($result))->toContain('Ver los parámetros', 'core.setting.view', 'Cuentas y configuración', 'Sin alcance: vale en todo Nexus')
            ->and(textOf($result->querySelector('[data-testid="who-has-admin"]')))->toContain('Administración', 'Todo')
            ->and(textOf($result->querySelector('[data-testid="who-has-coordinador"]')))->toContain('Coordinador de carrera', 'Suelto', '1')
            ->and(textOf($result->querySelector('[data-testid="who-missing-docente"]')))->toContain(grantProblemFor(Role::Docente, 'core.setting.view'))
            ->and(textOf($result->querySelector('[data-testid="who-missing-jefe_departamento"]')))->toContain('Dar');
    });

    it('CF-03: muestra el candado, las dimensiones y a qué unidades aplica', function () {
        $user = textOf(domOf(permissionsPage(['vista' => 'quien', 'permiso' => 'core.user.create']))->querySelector('[data-testid="who-result"]'));
        $teacher = textOf(domOf(permissionsPage(['vista' => 'quien', 'permiso' => 'faculty.teacher.view']))->querySelector('[data-testid="who-result"]'));

        expect($user)->toContain('Solo del admin')
            ->and($teacher)->toContain('Solo en departamentos académicos y la Subdirección Académica');
    });

    it('busca un permiso por etiqueta o clave', function () {
        $page = permissionsPage(['vista' => 'quien'])->set('whoSearch', 'core.setting');

        expect(array_map(fn ($option): string => $option->getAttribute('data-key'), iterator_to_array(domOf($page)->querySelectorAll('[data-testid="who-option"]'))))->toBe(['core.setting.view', 'core.setting.update']);
    });

    it('da el permiso a un puesto desde ¿Quién puede…?, como suelto', function () {
        $page = permissionsPage(['vista' => 'quien', 'permiso' => 'core.setting.view'])->call('grantFromWho', 'jefe_departamento', 'core.setting.view')->assertHasNoErrors();

        expect(loosePermissionsOf(Role::JefeDepartamento))->toBe(['core.setting.view'])
            ->and(lastToastOf($page)['title'])->toBe('Jefe de departamento ya puede «Ver los parámetros»');

        $page->call('grantFromWho', 'docente', 'core.setting.view')->assertHasErrors(['permission']);
    });
});

describe('cambios en la siguiente petición y bitácora', function () {
    it('CF-01: lo que se da en la pantalla vale en la siguiente petición y queda en la bitácora', function () {
        $user = nonAdminHolder(Role::Coordinador);

        permissionsPage(['puesto' => 'coordinador'])->call('openAddLoose')->set('looseKeys', ['core.setting.view'])->call('grantLoose');

        $this->actingAs($user);
        app()->forgetScopedInstances();

        expect(app(Actor::class)->allowsAny(CorePermission::SettingView))->toBeTrue()
            ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
            ->and(Activity::query()->latest('id')->first()->description)->toBe('Dio el permiso «Ver los parámetros» al puesto Coordinador de carrera');
    });
});

describe('otros puestos e invitados', function () {
    $roles = [
        'subdirección' => [Role::Subdireccion],
        'jefe de departamento' => [Role::JefeDepartamento],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente],
        'coordinador' => [Role::Coordinador],
        'docente' => [Role::Docente],
        'alumno' => [Role::Alumno],
    ];

    it('reciben 403 en la página y no ven la entrada del menú', function (Role $role) {
        $user = nonAdminHolder($role);

        $this->actingAs($user)->get(route('core.permissions'))->assertForbidden();

        expect(collect(app(Navigation::class)->visibleTo(app(Actor::class)))->contains(fn (NavigationItem $item): bool => $item->label === 'Puestos y permisos'))->toBeFalse();
    })->with($roles);

    it('reciben 403 en cada acción del componente aunque la llamen directo con una página que abrió el admin', function (Role $role, string $method, array $arguments) {
        $user = nonAdminHolder($role);
        $own = PermissionSet::factory()->withPermissions([CorePermission::TermView])->create(['name' => 'Propio']);
        DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);
        $page = permissionsPage(['puesto' => 'coordinador'])->set('addRoleId', $own->id)->set('looseKeys', ['core.setting.view'])->set('name', 'Otro')->set('permissions', ['core.term.view']);
        $before = Activity::count();

        $this->actingAs($user);
        $page->call($method, ...array_map(fn (mixed $argument): mixed => match ($argument) {
            'own' => $own->id,
            'factory' => factorySetOf(Role::Coordinador)->id,
            default => $argument,
        }, $arguments))->assertForbidden();

        expect(Activity::count())->toBe($before)
            ->and(setNamesOfPosition(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)'])
            ->and(loosePermissionsOf(Role::Coordinador))->toBe(['core.term.view'])
            ->and($own->fresh()->is_active)->toBeTrue();
    })->with($roles)->with([
        'ver la pantalla' => ['$refresh', []],
        'abrir agregar rol' => ['openAddRole', []],
        'agregar rol' => ['assignRole', []],
        'quitar rol' => ['unassignRole', ['factory']],
        'abrir sueltos' => ['openAddLoose', []],
        'dar sueltos' => ['grantLoose', []],
        'quitar suelto' => ['revokeLoose', ['core.term.view']],
        'restablecer puesto' => ['restorePosition', []],
        'aplicar nuevos' => ['applyFactoryUpdates', ['own']],
        'nuevo rol' => ['openNewRole', []],
        'editar rol' => ['editRole', ['own']],
        'guardar rol' => ['saveRole', []],
        'restablecer rol' => ['restoreRole', ['factory']],
        'desactivar rol' => ['setRoleActive', ['own', false]],
        'borrar rol' => ['deleteRole', ['own']],
        'marcar un grupo' => ['toggleGroup', ['loose', 'core_accounts']],
        'dar desde ¿quién puede…?' => ['grantFromWho', ['coordinador', 'core.setting.view']],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('core.permissions'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada «Puestos y permisos» va en Configuración y apunta a la pantalla', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Puestos y permisos');

        expect($item->route)->toBe('core.permissions')
            ->and($item->group->value)->toBe('configuracion')
            ->and(route('core.permissions', absolute: false))->toBe('/configuracion/puestos-y-permisos');

        $this->actingAs($this->admin);

        expect(collect(app(Navigation::class)->visibleTo(app(Actor::class)))->contains(fn (NavigationItem $item): bool => $item->label === 'Puestos y permisos'))->toBeTrue();
    });
});
