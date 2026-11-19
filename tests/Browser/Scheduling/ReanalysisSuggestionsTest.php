<?php

use App\Modules\Demand\Models\DemandForecast;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| Sugerencias del re-análisis en el navegador (tarea 8.3a; RA-07): el jefe abre el drawer de una materia faltante desde su
| bloque, ve la sugerencia «Ampliar cupo» con su evidencia y la aplica; la sugerencia se va y la página lo marca como ya
| ajustado. En celular, «Demanda por carrera» se lee completa sin desplazarse de lado (F-07 del ensayo 8.11).
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

it('F-07: en celular, «Demanda por carrera» apila cada carrera con todas sus cifras, sin desplazarse de lado; desde 768 px es la tabla', function () {
    $wrapper = "document.querySelector('[data-testid=suggestions-evidence]').parentElement";
    $allInside = "[...document.querySelectorAll('[data-testid=suggestions-career] > td')].every((cell) => { const box = cell.getBoundingClientRect(); return box.width > 0 && box.right <= window.innerWidth; })";
    $header = "getComputedStyle(document.querySelector('[data-testid=suggestions-evidence] thead')).display";

    $page = visit('/login')
        ->fill('username', '19110061')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');

    foreach ([320, 375, 390] as $width) {
        $page->resize($width, 844)
            ->navigate('/scheduling/reanalysis')
            ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
            ->assertSeeIn('[data-testid="suggestions-career"][data-career="ISIC"]', 'AHORA')
            ->assertSeeIn('[data-testid="suggestions-career"][data-career="ISIC"]', 'DIF.')
            ->assertSeeIn('[data-testid="suggestions-career"][data-career="ISIC"]', 'Faltante')
            ->assertScript("{$wrapper}.scrollWidth <= {$wrapper}.clientWidth")
            ->assertScript($allInside)
            ->assertScript($header, 'none');
    }

    $page->resize(768, 900)
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSeeIn('[data-testid="suggestions-evidence"] thead', 'AHORA')
        ->assertScript($header, 'table-header-group')
        ->assertScript("{$wrapper}.scrollWidth <= {$wrapper}.clientWidth")
        ->assertNoJavaScriptErrors();
});
