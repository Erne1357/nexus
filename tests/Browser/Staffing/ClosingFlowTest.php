<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Scheduling\Enums\GroupStatus;
use Tests\Support\Staffing\ClosingWorld;

/*
| Cierre de la carga en el navegador (tarea 4.1): el jefe entra desde el menú a la carga de su departamento, que está
| lista para cerrar, confirma el cierre con un comentario y la ve «Cerrada», con sus grupos confirmados.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->world->head(['username' => '19110050']);
    $this->groups = [$this->world->group(), $this->world->group()];
});

it('el jefe cierra su carga y la ve cerrada', function () {
    visit('/login')
        ->fill('username', '19110050')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/staffing/closing')
        ->assertSee('Cierre de la carga')
        ->assertSee('Lista para cerrar')
        ->click('@action-close')
        ->assertSee('Al cerrar, los 2 grupos activos quedan confirmados')
        ->fill('@close-comment', 'Todo asignado y sin choques.')
        ->click('@confirm-close')
        ->assertSee('Carga cerrada')
        ->assertSeeIn('@load-status', 'Cerrada')
        ->assertSee('Todo asignado y sin choques.')
        ->assertNoJavaScriptErrors();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and(array_map(fn ($group) => $group->fresh()->status, $this->groups))->toBe([GroupStatus::Confirmed, GroupStatus::Confirmed]);
});
