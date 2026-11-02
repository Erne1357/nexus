<?php

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Rules\TermRules;

it('sugiere el nombre del periodo según el último dígito del código', function (string $code, ?string $name) {
    expect((new TermRules)->suggestedName($code))->toBe($name);
})->with([
    ['20271', 'Ene-Jun 2027'],
    ['20272', 'Verano 2027'],
    ['20273', 'Ago-Dic 2027'],
    [' 20271 ', 'Ene-Jun 2027'],
    ['20274', null],
    ['2027', null],
    ['abcd1', null],
]);

it('da una etiqueta en español a cada etapa y a cada estado de etapa', function () {
    expect(Stage::Staffing->label())->toBe('Asignación docente')
        ->and(Stage::Reanalysis->label())->toBe('Re-análisis')
        ->and(StageStatus::InProgress->label())->toBe('En curso')
        ->and(StageStatus::Unscheduled->label())->toBe('Sin fecha');

    foreach (Stage::cases() as $stage) {
        expect($stage->label())->not->toBe('');
    }

    foreach (StageStatus::cases() as $status) {
        expect($status->tone())->not->toBeNull();
    }
});
