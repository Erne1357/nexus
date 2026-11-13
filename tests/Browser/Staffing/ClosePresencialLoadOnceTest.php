<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use Tests\Support\Staffing\ClosingWorld;

/*
| M1 en el navegador (maqueta U2, opción A): el jefe ve una sola carga presencial con grupos de C1 y C2, filtra las
| justificaciones por campus sin cambiar de carga, la cierra y la publica una vez; la versión publicada lleva los grupos
| de los dos campus, cada uno con el suyo.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->world->head(['username' => '19110051']);
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->groups = [
        $this->world->group(load: $this->world->loadIn($c1)),
        $this->world->group(load: $this->world->loadIn($c1)),
        $this->world->group(),
    ];
});

it('el jefe cierra y publica una vez la carga presencial con grupos de C1 y C2', function () {
    $page = visit('/login')
        ->fill('username', '19110051')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/staffing/closing')
        ->assertSee('Cierre de la carga')
        ->assertSee('Una sola carga: 2 grupos en C1 y 1 en C2. Se cierra y se publica una vez.')
        ->assertSeeIn('@campus-count-todos', '3')
        ->assertSeeIn('@campus-count-C1', '2')
        ->assertSeeIn('@campus-count-C2', '1')
        ->click('@campus-filter-C2')
        ->assertQueryStringHas('campus', 'C2')
        ->assertSee('Lista para cerrar')
        ->click('@action-close')
        ->assertSee('Al cerrar, los 3 grupos activos quedan confirmados')
        ->fill('@close-comment', 'Carga presencial completa en C1 y C2.')
        ->click('@confirm-close')
        ->assertSee('Carga cerrada')
        ->assertSeeIn('@load-status', 'Cerrada')
        ->click('@publish-next')
        ->click('@publish-confirm')
        ->assertSee('Versión 1 publicada')
        ->assertSeeIn('@load-status', 'Publicada')
        ->assertNoJavaScriptErrors();

    $snapshot = LoadSnapshot::query()->where('academic_load_id', $this->world->load->id)->sole();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Published)
        ->and(collect($snapshot->payload['groups'])->pluck('campus.code')->sort()->values()->all())->toBe(['C1', 'C1', 'C2']);
});
