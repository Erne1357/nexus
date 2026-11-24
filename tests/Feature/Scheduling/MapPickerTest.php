<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/*
| Selector de salón sobre el mapa 3D (tarea 14 de la orden de selección, diseño §6.5): el componente solo recibe llaves
| de mapa y motivos; el modo «elegir» se enciende con `window.UbicaTec.enablePick` y el Inicio nunca lo llama.
*/

beforeEach(function () {
    config(['nexus.campus_map_3d' => true]);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();
});

it('el selector pinta el mapa con los elegibles y los rechazados con su motivo', function () {
    actingAsRole($this->docente, Role::Docente);

    Livewire::test('scheduling::components.map-picker', [
        'eligible' => ['Edificio Sistemas|701', 'Edificio Sistemas|702'],
        'rejected' => ['Edificio Sistemas|703' => 'Ocupado en ese horario'],
    ])
        ->assertSet('eligible', ['Edificio Sistemas|701', 'Edificio Sistemas|702'])
        ->assertSet('rejected', ['Edificio Sistemas|703' => 'Ocupado en ese horario'])
        ->assertSeeHtml('data-testid="map-picker"')
        ->assertSeeHtml('id="ubicatec-data"')
        ->assertSeeHtml('enablePick');
});

it('el selector acepta el campus como parámetro opcional', function () {
    actingAsRole($this->docente, Role::Docente);

    Livewire::test('scheduling::components.map-picker', ['campusId' => $this->campus->id])
        ->assertSet('campusId', $this->campus->id)
        ->assertSet('eligible', [])
        ->assertSet('rejected', []);
});

it('sin la bandera del visor 3D el selector muestra un aviso y no carga el mapa', function () {
    config(['nexus.campus_map_3d' => false]);
    actingAsRole($this->docente, Role::Docente);

    Livewire::test('scheduling::components.map-picker')
        ->assertSeeHtml('data-testid="map-picker-static"')
        ->assertDontSeeHtml('id="ubicatec-data"');
});

it('el Inicio sigue siendo el mapa de siempre: no llama a enablePick', function () {
    actingAsRole($this->docente, Role::Docente)
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="campus-map"', false)
        ->assertSee('id="ubicatec-data"', false)
        ->assertDontSee('enablePick', false)
        ->assertDontSee('map-picker', false);
});
