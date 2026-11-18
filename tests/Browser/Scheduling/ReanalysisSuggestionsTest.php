<?php

use App\Modules\Demand\Models\DemandForecast;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| Sugerencias del re-análisis en el navegador (tarea 8.3a; RA-07): el jefe abre el drawer de una materia faltante desde su
| bloque, ve la sugerencia «Ampliar cupo» con su evidencia y la aplica; la sugerencia se va y la página lo marca como ya
| ajustado.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $jefe = $this->world->head(['username' => '19110061']);
    $this->world->published()->gradesArrived()->inReanalysis($jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 70]);
});

it('el jefe abre las sugerencias de una materia faltante y aplica «Ampliar cupo»', function () {
    $a = $this->world->groups['A'];
    $key = "expand_quota:{$a->id}:{$this->world->isc->id}";

    visit('/login')
        ->fill('username', '19110061')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSee('Ampliar el cupo de ISIC en el grupo A de 40 a 50')
        ->assertSee('ISIC: 70 esperados · 40 lugares · faltan 30')
        ->click("[data-key=\"{$key}\"] [data-testid=\"suggestion-apply\"]")
        ->assertSee('Sugerencia aplicada')
        ->assertDontSee('Ampliar el cupo de ISIC en el grupo A de 40 a 50')
        ->assertSee('Ya ajustaste A: cupo 40 → 50')
        ->assertNoJavaScriptErrors();

    expect($a->fresh()->capacity)->toBe(50);
});
