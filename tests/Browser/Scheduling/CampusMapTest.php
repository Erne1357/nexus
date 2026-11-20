<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| Prueba de humo del mapa real del Inicio (1.24.1, ADR-0030): con el visor 3D encendido el docente ve el mapa y su
| panel, busca y no hay errores de JavaScript.
*/

it('el docente ve el mapa del campus y no hay errores de JavaScript', function () {
    config(['nexus.campus_map_3d' => true]);
    $campus = Campus::factory()->create(['code' => 'C2']);
    User::factory()->withRole(Role::Docente, ['campus_id' => $campus->id])->create(['username' => '19110001']);

    visit('/login')
        ->fill('username', '19110001')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertPresent('[data-testid="campus-map"]')
        ->assertSee('Mapa del campus')
        ->wait(3)
        ->assertNoJavaScriptErrors();
});
