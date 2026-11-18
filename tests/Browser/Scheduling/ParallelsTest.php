<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| «Paralelos» en el navegador (tarea 8.8, maqueta B): el jefe de Ciencias Básicas entra a la bandeja, abre la pestaña
| «Paralelos», abre Cálculo Integral en Campus I, mueve los esperados de ILOG de un mixto a otro, arma con la confirmación
| de Core y la lista la marca armada.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    TermOpening::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->world->modality->id]);
    $this->world->request($this->world->career('ISIC'), 60, 2);
    $this->world->request($this->world->career('IIND'), 40, 2);
    $this->world->request($this->world->career('ILOG'), 10, 1);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create(['username' => '19110004']);
});

it('el jefe abre Paralelos desde la bandeja, mueve esperados entre mixtos y arma', function () {
    $page = visit('/login')
        ->fill('username', '19110004')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/inbox')
        ->click('@tab-parallels')
        ->assertPathIs('/scheduling/parallels')
        ->assertSee('Bastan juntando')
        ->assertSeeIn('@parallels-state-ACF-0902-C1', 'Por armar')
        ->click('@parallels-open-ACF-0902-C1')
        ->assertSee('Cálculo Integral en Campus I')
        ->assertSee('Reparto de los esperados')
        ->assertSee('Poco lleno');

    $page->fill('[aria-label="Esperados de ILOG en Mixto 1"]', '')
        ->waitForText('Quedan de menos 10 de ILOG')
        ->fill('[aria-label="Esperados de ILOG en Mixto 2"]', '10')
        ->waitForText('Usar lo de Nexus')
        ->assertDontSee('Quedan de menos 10 de ILOG')
        ->press('Armar grupos')
        ->assertSeeIn('#nexus-confirm', '¿Armar los paralelos de Cálculo Integral en Campus I?')
        ->assertSeeIn('#nexus-confirm', 'Quedan 4 grupos: nacen 4 nuevos.')
        ->click('@confirm-accept')
        ->waitForText('Armaste los paralelos de ACF-0902 en C1')
        ->assertSeeIn('@parallels-state-ACF-0902-C1', 'Armada')
        ->assertNoJavaScriptErrors();

    expect(Group::query()->with('quotas')->get()->map(fn (Group $group): array => $group->quotas->pluck('max_seats')->sort()->values()->all())->sort()->values()->all())
        ->toContain([5, 10]);
});
