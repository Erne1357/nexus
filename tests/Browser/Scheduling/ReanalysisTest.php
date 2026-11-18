<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Re-análisis de la carga» en el navegador (tarea 8.2, pantalla; RA-01, RA-02, RA-10): el jefe abre la pantalla con su
| carga publicada y las calificaciones del semestre actual, inicia el re-análisis con la confirmación de Nexus, ve la
| diferencia de demanda por materia y campus, y lo cancela sin haber cambiado nada: la carga vuelve a la versión 1.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->world->head(['username' => '19110060']);
    $this->world->published()->gradesArrived();
});

it('el jefe inicia el re-análisis, ve los bloques por materia y lo cancela sin cambios', function () {
    $page = visit('/login')
        ->fill('username', '19110060')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->assertSee('Re-análisis de la carga')
        ->assertSee('Ya puedes re-analizar la carga.')
        ->click('@start-reanalysis')
        ->assertSee('¿Iniciar el re-análisis de Ciencias Básicas · Presencial?')
        ->click('@confirm-accept')
        ->assertSee('Re-análisis iniciado')
        ->assertSeeIn('@load-status', 'Re-análisis')
        ->assertSee('Cálculo Integral')
        ->assertSee('Nuevo ingreso: 30')
        ->assertVisible('[data-subject="ACF-0902"][data-campus="C2"] [data-career="ISIC"]');

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Reanalysis);

    $page->click('@cancel-reanalysis')
        ->assertSee('¿Cancelar el re-análisis?')
        ->click('@confirm-accept')
        ->assertSee('Re-análisis cancelado')
        ->assertSeeIn('@load-status', 'Publicada · v1')
        ->assertNoJavaScriptErrors();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Published)
        ->and(LoadSnapshot::query()->where('academic_load_id', $this->world->load->id)->count())->toBe(1);
});
