<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Bitácora» (`/configuracion/bitacora`, core::pages.activity; CF-S5, CF-03, HC-18): solo del admin y de solo lectura. La
| tabla con la barra de filtros estándar y, al tocar un renglón, un drawer con el detalle.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'admin01', 'name' => 'Admin Uno']);
    $this->campus = Campus::factory()->create(['code' => 'C7', 'name' => 'Campus Siete']);

    $this->entry = activity()
        ->performedOn($this->campus)
        ->causedBy($this->admin)
        ->event('updated')
        ->withChanges(['attributes' => ['name' => 'Campus Siete'], 'old' => ['name' => 'Campus Seis']])
        ->withProperties(['nota' => 'de prueba'])
        ->log('Editó el campus C7');
});

it('el admin entra por la ruta y ve las entradas con quién, qué y tipo de registro', function () {
    $this->actingAs($this->admin)->get(route('core.activity'))
        ->assertOk()
        ->assertSee('Bitácora')
        ->assertSee('Editó el campus C7')
        ->assertSee('admin01')
        ->assertSee('updated');

    expect(route('core.activity', absolute: false))->toBe('/configuracion/bitacora');
});

it('filtra por texto, por quién y por evento', function () {
    activity()->event('created')->log('Otra cosa distinta');

    Livewire::actingAs($this->admin)->test('core::pages.activity')
        ->assertSee('Otra cosa distinta')
        ->set('search', 'campus C7')
        ->assertSee('Editó el campus C7')
        ->assertDontSee('Otra cosa distinta')
        ->set('search', '')
        ->set('event', 'created')
        ->assertSee('Otra cosa distinta')
        ->assertDontSee('Editó el campus C7')
        ->set('event', '')
        ->set('who', 'Admin Uno')
        ->assertSee('Editó el campus C7')
        ->assertDontSee('Otra cosa distinta');
});

it('CF-S5: al tocar un renglón abre el drawer con el antes y el después, el puesto y el registro afectado', function () {
    Livewire::actingAs($this->admin)->test('core::pages.activity')
        ->call('openEntry', $this->entry->id)
        ->assertSet('showDetail', true)
        ->assertSeeHtml('data-testid="activity-changes"')
        ->assertSee('Campus Seis')
        ->assertSee('Campus Siete')
        ->assertSee('de prueba')
        ->assertSee('C7');
});

it('muestra el puesto con que actuó la persona', function () {
    $id = DB::table('activity_log')->insertGetId([
        'description' => 'Con puesto',
        'causer_type' => User::class,
        'causer_id' => $this->admin->id,
        'properties' => json_encode(['acting_as' => 'admin']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($this->admin)->test('core::pages.activity')
        ->call('openEntry', $id)
        ->assertSee('Administración');
});

it('un rango de fechas manipulado no rompe la página: se descarta', function () {
    Livewire::actingAs($this->admin)->test('core::pages.activity')
        ->set('from', '2026-13-45')
        ->set('to', 'x')
        ->assertOk()
        ->assertSee('Editó el campus C7');
});

it('HC-18: la pantalla no escribe nada en la bitácora, ni al filtrar ni al abrir el detalle', function () {
    $before = Activity::query()->count();

    Livewire::actingAs($this->admin)->test('core::pages.activity')
        ->set('search', 'campus')
        ->set('who', 'admin')
        ->call('openEntry', $this->entry->id);

    expect(Activity::query()->count())->toBe($before);
});

it('CF-03: otro puesto no entra ni abre una entrada llamándola directo', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    $this->actingAs($user)->get(route('core.activity'))->assertForbidden();

    Livewire::actingAs($user)->test('core::pages.activity')->assertForbidden();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: el menú muestra «Bitácora» solo al admin', function () {
    $this->actingAs($this->admin)->get(route('home'))->assertSee('Bitácora');

    $docente = User::factory()->withRole(Role::Docente)->create();
    $this->actingAs($docente)->get(route('home'))->assertDontSee('Bitácora');
});
