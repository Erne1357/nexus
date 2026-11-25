<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Staffing\Actions\RecordPriorChoice;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Queries\SelectionMonitorQuery;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-16 en las pantallas (tarea 1.41): la lista de la orden (drawer del docente), el monitor y la vista previa del
| admin muestran aparte, arriba, a quien tiene elección previa, con sus horas y sin turno. Otro departamento no los ve.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create(SelectionStatus::Published);
    $this->previo = $this->world->teacher(name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $this->espera = $this->world->teacher(name: 'Docente Espera', position: 1, slotStartsAt: CarbonImmutable::now()->addDay());
    RoleAssignment::factory()->for($this->espera->user)->create(['role' => Role::Docente]);
    // La Action solo registra con la selección en borrador (SEL-16): se registra así y se publica el escenario como estaba.
    $espera = $this->world->entryOf($this->espera)->only(['position', 'slot_index', 'slot_starts_at', 'slot_ends_at']);
    $this->world->selection->update(['status' => SelectionStatus::Draft]);
    app(RecordPriorChoice::class)->handle($this->world->term, $this->world->selection->modality, $this->previo, [['group' => $this->world->group('A', [[1, '09:00', '10:00']])]], $this->world->admin);
    $this->world->selection->update(['status' => SelectionStatus::Published]);
    $this->world->entryOf($this->espera)->update($espera);
});

it('SEL-16: el drawer de la orden los muestra aparte, arriba, con sus horas y sin turno', function () {
    $page = Livewire::actingAs($this->espera->user)->test('staffing::pages.offer')->set('showOrder', true);

    $html = preg_replace('/\s+/u', ' ', strip_tags($page->html()));

    expect($html)->toContain('Elección previa · ya traen sus grupos, sin turno')
        ->toContain('Docente Previo')
        ->toContain('5 h')
        ->toContain('Sin turno');
    expect(strpos($html, 'Elección previa · ya traen'))->toBeLessThan(strpos($html, 'Docente Espera'));
});

it('SEL-16: el monitor del admin los lista aparte y no los cuenta como turnos', function () {
    $this->world->selection->update(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);

    Livewire::actingAs($this->world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $this->world->selection->id])
        ->assertSeeHtml('data-testid="monitor-prior"')
        ->assertSee('Docente Previo')
        ->assertSee('Elección previa');

    // No cuentan como turno: el avance es 0 de 1 (solo «Docente Espera» tiene turno) y «lugar X de N» no los incluye.
    $monitor = app(SelectionMonitorQuery::class)->for($this->world->selection->fresh(), CarbonImmutable::now());

    expect($monitor)->total->toBe(1)->done->toBe(0)->and($monitor['prior'])->toHaveCount(1)->and($monitor['rows'])->toHaveCount(1);

    $html = preg_replace('/\s+/u', ' ', strip_tags(Livewire::actingAs($this->espera->user)->test('staffing::pages.offer')->set('showOrder', true)->html()));

    expect($html)->toContain('lugar 1 de 1')->not->toContain('de 2');
});

it('SEL-16: la vista previa del paso 6 los muestra bajo «Elección previa»', function () {
    $this->world->selection->update(['status' => SelectionStatus::Draft, 'starts_at' => now()->addDays(2)]);

    Livewire::actingAs($this->world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $this->world->selection->id])
        ->set('step', 6)
        ->assertSeeHtml('data-testid="preview-prior"')
        ->assertSee('sin turno');
});

it('alcance: otro departamento no ve la lista de la orden ni el monitor de esta selección', function () {
    $other = SelectionWorld::create(SelectionStatus::Published);
    $stranger = $other->teacher(name: 'Docente Ajeno');
    RoleAssignment::factory()->for($stranger->user)->create(['role' => Role::Docente]);

    $page = Livewire::actingAs($stranger->user)->test('staffing::pages.offer')->set('showOrder', true);

    expect($page->html())->not->toContain('Docente Previo')->not->toContain('Docente Espera');

    Livewire::actingAs($stranger->user)->test('staffing::pages.selection-admin', ['seleccion' => $this->world->selection->id])->assertForbidden();
});
