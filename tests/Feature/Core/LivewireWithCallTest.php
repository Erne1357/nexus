<?php

use App\Modules\Core\Models\User;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/*
| Endurecimiento (tarea 4.8): `with()` de un componente arma los datos de su vista. Livewire lo deja llamar desde el
| cliente como una acción (`$wire.call('with')`) y devolvería todo lo que arma, más de lo que la vista pinta. Core lo
| bloquea para todo componente (BlockViewDataCalls).
*/

it('no deja llamar with() desde el cliente', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(fn () => Livewire::test('core::pages.terms')->call('with'))->toThrow(MethodNotFoundException::class);
});

it('las acciones de siempre se siguen llamando', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('core::pages.terms')->call('create')->assertOk();
});
