<?php

use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| El estado de una etapa se ve igual en la insignia, la barra y la leyenda de la línea de tiempo (DESIGN.md,
| "Línea de tiempo de etapas"): azul en curso, gris próxima, verde terminada y punteada sin fecha.
*/

it('da a cada estado el tono de su barra y su icono', function (StageStatus $status, StatusTone $tone, string $icon) {
    expect($status->tone())->toBe($tone)->and($status->icon())->toBe($icon);
})->with([
    'en curso' => [StageStatus::InProgress, StatusTone::Info, 'o-clock'],
    'próxima' => [StageStatus::Upcoming, StatusTone::Neutral, 'o-calendar'],
    'terminada' => [StageStatus::Finished, StatusTone::Success, 'o-check-circle'],
    'sin fecha' => [StageStatus::Unscheduled, StatusTone::Draft, 'o-minus-circle'],
]);

it('atenúa la barra de una etapa terminada y no pinta la de una sin fecha', function () {
    expect(StageStatus::InProgress->barClasses())->toBe('bg-info')
        ->and(StageStatus::Finished->barClasses())->toContain('bg-success/35')->toContain('border-success/60')
        ->and(StageStatus::Unscheduled->barClasses())->toBe('');
});
