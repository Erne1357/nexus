<?php

use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Aplicar las N elegidas» del re-análisis en el navegador (seguimiento de la tarea 8.3a; RA-07): el jefe abre las
| sugerencias de una materia y vienen marcadas las dos que se pueden aplicar (ampliar un cupo de la carrera faltante y
| cerrar un grupo de la sobrante; confirmado el 18 de noviembre de 2026); desmarca y vuelve a marcar una, las aplica
| juntas después de la confirmación de Nexus y el toast dice que se aplicaron. Después de aplicar y al encontrarle lugar a
| «Abrir un grupo», las casillas que se vuelven a marcar salen marcadas en el navegador y la cuenta coincide (F-06 del
| ensayo 8.11).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->chosenScript = fn (string $key): string => 'document.querySelector(\'[data-key="'.$key.'"] [data-testid="suggestion-choose"]\').checked';
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

it('F-06: después de aplicar el lote, la que sigue pudiéndose aplicar sale marcada y la cuenta lo dice', function () {
    $a = $this->world->groups['A'];
    Room::factory()->capacity(70)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-70']);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 120]);
    $roomKey = "change_room:{$a->id}:{$this->world->isc->id}";

    visit('/login')
        ->fill('username', '19110064')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar las 2 elegidas')
        ->assertScript(($this->chosenScript)($roomKey), false)
        ->click('[data-testid="suggestions-apply-chosen"]')
        ->click('[data-testid="confirm-accept"]')
        ->assertSee('Se aplicaron las 2 sugerencias')
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar la elegida')
        ->assertSeeIn('[data-testid="suggestions-chosen-count"]', '1 elegida')
        ->assertScript(($this->chosenScript)($roomKey), true)
        ->assertScript('document.querySelectorAll(\'[data-testid="suggestion-choose"]:checked\').length', 1)
        ->assertNoJavaScriptErrors();
});

it('F-06: «Abrir un grupo» sale marcado en cuanto el motor le encuentra lugar', function () {
    $openKey = "open_group:{$this->world->isc->id}";

    visit('/login')
        ->fill('username', '19110064')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar las 2 elegidas')
        ->click("[data-key=\"{$openKey}\"] [data-testid=\"suggestion-find\"]")
        ->assertSeeIn('[data-testid="suggestions-apply-chosen"]', 'Aplicar las 3 elegidas')
        ->assertScript(($this->chosenScript)($openKey), true)
        ->assertScript('document.querySelectorAll(\'[data-testid="suggestion-choose"]:checked\').length', 3)
        ->assertNoJavaScriptErrors();
});
