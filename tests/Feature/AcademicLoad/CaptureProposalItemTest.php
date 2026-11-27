<?php

use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateProposalItem marca la fila como corregida (`captured_at` y `captured_by`; tarea 5.5, confirmado el 16 de noviembre de
| 2026) cuando cambian los esperados o los grupos, también al dejar en cero los grupos de un campus inactivo (M14). La nota
| sola no cuenta. La bitácora no cambia de forma.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->item = ProposalItem::factory()->create(['expected_students' => 40, 'requested_groups' => 2, 'eligible_students' => 40]);
});

it('marca captured_at y captured_by al cambiar los esperados', function () {
    app(UpdateProposalItem::class)->handle($this->item, 45, 2, null, $this->actor);

    $fresh = $this->item->fresh();

    expect($fresh->captured_at)->not->toBeNull()
        ->and($fresh->captured_by)->toBe($this->actor->id);
});

it('marca captured_at al cambiar solo los grupos', function () {
    app(UpdateProposalItem::class)->handle($this->item, 40, 1, null, $this->actor);

    expect($this->item->fresh()->captured_at)->not->toBeNull();
});

it('la nota sola no marca la fila, y guardar los mismos valores tampoco', function () {
    app(UpdateProposalItem::class)->handle($this->item, 40, 2, 'Una nota', $this->actor);
    app(UpdateProposalItem::class)->handle($this->item, 40, 2, 'Una nota', $this->actor);

    $fresh = $this->item->fresh();

    expect($fresh->notes)->toBe('Una nota')
        ->and($fresh->captured_at)->toBeNull()
        ->and($fresh->captured_by)->toBeNull();
});

it('M14: dejar en cero los grupos de un campus inactivo también marca la fila', function () {
    $campus = Campus::factory()->inactive()->create();
    $item = ProposalItem::factory()->create(['campus_id' => $campus->id, 'requested_groups' => 2]);

    app(UpdateProposalItem::class)->zeroRequestedGroups($item, $this->actor);

    expect($item->fresh()->captured_at)->not->toBeNull();
});

it('la bitácora lleva solo lo que cambió, sin la marca de captura', function () {
    app(UpdateProposalItem::class)->handle($this->item, 45, 2, null, $this->actor);

    $log = Activity::query()->where('subject_id', $this->item->id)->latest()->firstOrFail();

    expect(array_keys($log->attribute_changes['attributes']))->toBe(['expected_students'])
        ->and(array_keys($log->attribute_changes['old']))->toBe(['expected_students']);
});
