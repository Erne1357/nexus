<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\ModalityQuery;
use App\Modules\Core\Queries\TermQuery;
use Livewire\Livewire;

/*
| Un perfil de flujo guardado inválido es un estado real (UpdateModality lo tolera y la pantalla de Modalidades lo marca
| «Perfil inválido»). Lo que se deriva del perfil (dónde se dan los grupos de la modalidad, MS4; los campus donde el
| periodo está abierto; la jornada de los salones, MS34) trata esa modalidad como sin salones, y el panel «Abrir el
| periodo» la muestra con la insignia en lugar de fallar.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->broken = Modality::factory()->create(['code' => 'M01', 'name' => 'Rota']);
    $this->broken->forceFill(['settings' => ['block_minutes' => 45]])->save();
});

it('el panel «Abrir el periodo» de una modalidad con perfil inválido responde con la insignia y sin campus', function () {
    $preview = app(TermQuery::class)->openingPreview($this->term, $this->broken);

    expect($preview['profile'])->toBeNull()
        ->and($preview['campuses'])->toBe([]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('core::pages.term', ['term' => $this->term->id])
        ->call('prepareOpen', $this->broken->id)
        ->assertOk()
        ->assertSet('showOpen', true)
        ->assertSee('Perfil inválido');
});

it('MS4: una modalidad con perfil inválido cuenta como sin salones: no tiene campus ni abre campus', function () {
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->broken->id]);

    expect(app(CampusQuery::class)->of($this->broken))->toBeEmpty()
        ->and(app(CampusQuery::class)->openIn($this->term))->toBeEmpty();

    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);

    expect(app(CampusQuery::class)->openIn($this->term)->pluck('code')->all())->toBe(['C1', 'C2']);
});

it('MS34: la jornada de los salones se salta una modalidad activa con perfil inválido', function () {
    expect(app(ModalityQuery::class)->roomsWindow()->toArray())->toBe(modalityOf('PRES')->profile->toArray());
});
