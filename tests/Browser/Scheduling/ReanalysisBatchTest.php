<?php

use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Scheduling\Enums\GroupStatus;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Aplicar las N elegidas» del re-análisis en el navegador (seguimiento de la tarea 8.3a; RA-07): el jefe abre las
| sugerencias de una materia y vienen marcadas las dos que se pueden aplicar (ampliar un cupo de la carrera faltante y
| cerrar un grupo de la sobrante; confirmado el 18 de noviembre de 2026); desmarca y vuelve a marcar una, las aplica
| juntas después de la confirmación de Nexus y el toast dice que se aplicaron.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $jefe = $this->world->head(['username' => '19110064']);
    $this->world->published()->gradesArrived()->inReanalysis($jefe);
    $this->e = $this->world->group($this->world->integral, $this->world->iind, 20, $this->world->c2, 2, day: 2);
    $this->e->update(['teacher_id' => null]);

    foreach (['isc' => 70, 'iind' => 25] as $career => $expected) {
        DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->{$career}->id)->update(['expected_students' => $expected]);
    }
});

it('el jefe abre las sugerencias con las dos que se pueden aplicar ya marcadas y las aplica juntas', function () {
    $a = $this->world->groups['A'];
    $close = "[data-key=\"close_group:{$this->e->id}\"] [data-testid=\"suggestion-choose\"]";

    visit('/login')
        ->fill('username', '19110064')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar las 2 elegidas')
        ->click($close)
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar la elegida')
        ->click($close)
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar las 2 elegidas')
        ->click('[data-testid="suggestions-apply-chosen"]')
        ->assertSeeIn('[data-testid="confirm-dialog"]', '¿Aplicar las 2 sugerencias elegidas?')
        ->assertSeeIn('[data-testid="confirm-dialog"]', 'El grupo E se cancela y pierde su horario: mar 7:00–9:00.')
        ->click('[data-testid="confirm-accept"]')
        ->assertSee('Se aplicaron las 2 sugerencias')
        ->assertDontSee('Cerrar el grupo E')
        ->assertNoJavaScriptErrors();

    expect($a->fresh()->capacity)->toBe(50)
        ->and($this->e->fresh()->status)->toBe(GroupStatus::Cancelled);
});
