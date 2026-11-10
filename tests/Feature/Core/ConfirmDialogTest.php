<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| ADR-0023: el layout trae una sola vez el diálogo de confirmar de Core, y Campus confirma con él y no con
| `wire:confirm` (el diálogo del navegador).
*/

it('el layout trae el diálogo de confirmar una sola vez y Campus lo usa en lugar de wire:confirm', function () {
    $this->actingAs(User::factory()->admin()->create());
    Campus::factory()->create(['code' => 'C2']);

    $html = $this->get('/campus')->assertOk()->getContent();

    expect(substr_count($html, 'id="nexus-confirm"'))->toBe(1)
        ->and($html)->toContain('x-confirm="Nadie podrá trabajar en el campus C2 hasta que se reactive."')
        ->and($html)->toContain('data-confirm-tone="error"')
        ->and($html)->not->toContain('wire:confirm');
});
