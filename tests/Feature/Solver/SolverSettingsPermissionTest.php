<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/*
| Parámetros del motor (decisión del usuario, 18 de noviembre de 2026): Subdirección cambia los pesos SC-01 a SC-09 para
| todo el Tec con `solver.setting.update`, sin tocar los parámetros de otros módulos.
*/

function subdireccionForSolver(): User
{
    return User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
}

it('Subdirección cambia los pesos de las reglas blandas desde la pantalla de parámetros', function () {
    Livewire::actingAs(subdireccionForSolver())->test('core::pages.settings')
        ->set('values.solver__soft_weights.sc_01', '20')
        ->call('save', 'solver')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'solver.soft_weights')->value('value'))->toMatchArray(['sc_01' => 20, 'sc_02' => 6]);
});

it('un peso fuera de 0 a 100 no se guarda', function () {
    Livewire::actingAs(subdireccionForSolver())->test('core::pages.settings')
        ->set('values.solver__soft_weights.sc_01', '101')
        ->call('save', 'solver')
        ->assertHasErrors();

    expect(Setting::query()->where('key', 'solver.soft_weights')->count())->toBe(0);
});

it('Subdirección no cambia los parámetros de otros módulos', function () {
    Livewire::actingAs(subdireccionForSolver())->test('core::pages.settings')
        ->call('save', 'staffing')
        ->assertForbidden();

    expect(Setting::query()->where('key', 'like', 'staffing.%')->count())->toBe(0);
});
