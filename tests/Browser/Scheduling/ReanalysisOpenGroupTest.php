<?php

use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Abrir un grupo» del re-análisis en el navegador (tarea 8.3b; RA-07): el jefe abre las sugerencias de una materia
| faltante que ningún grupo alcanza, busca con el motor el horario y el salón del grupo nuevo en su tarjeta y lo aplica.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $jefe = $this->world->head(['username' => '19110062']);
    $this->world->published()->gradesArrived()->inReanalysis($jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 120]);
});

it('el jefe busca el lugar del grupo nuevo y aplica «Abrir un grupo»', function () {
    $card = "[data-key=\"open_group:{$this->world->isc->id}\"]";

    visit('/login')
        ->fill('username', '19110062')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/scheduling/reanalysis')
        ->click('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-suggestions"]')
        ->assertSee('Abrir un grupo de ACF-0902 para ISIC')
        ->assertSee('Sin docente: asígnalo en Asignación docente.')
        ->click("{$card} [data-testid=\"suggestion-find\"]")
        ->assertSeeIn("{$card} [data-testid=\"suggestion-schedule\"]", 'Lun y Mar 9:00–10:00')
        ->click("{$card} [data-testid=\"suggestion-apply\"]")
        ->assertSee('Grupo abierto')
        ->assertNoJavaScriptErrors();

    $group = Group::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->whereNull('teacher_id')->sole();

    expect($group->capacity)->toBe(35)
        ->and(GroupSession::query()->where('group_id', $group->id)->count())->toBe(2);
});
