<?php

use App\Modules\Core\Enums\StatusTone;
use App\Modules\Imports\Enums\BatchStatus;

/*
| Insignia del estado de un lote en el asistente (DESIGN.md, "Insignias de estado"): lo que la cola
| trabaja va en info (en cola o en revisión), lo validado en éxito suave, lo aplicado en éxito sólido
| (ya está en el catálogo, como lo publicado) y lo que falló en error.
*/

it('da a cada estado del lote el tono y el icono de su insignia', function (BatchStatus $status, StatusTone $tone, ?string $icon) {
    expect($status->tone())->toBe($tone)
        ->and($status->icon())->toBe($icon);
})->with([
    'subido, en la cola' => [BatchStatus::Uploaded, StatusTone::Info, 'o-clock'],
    'validando, en revisión' => [BatchStatus::Validating, StatusTone::Info, null],
    'validado' => [BatchStatus::Validated, StatusTone::Success, null],
    'falló' => [BatchStatus::Failed, StatusTone::Error, null],
    'aplicando, en la cola' => [BatchStatus::Applying, StatusTone::Info, 'o-clock'],
    'aplicado' => [BatchStatus::Applied, StatusTone::SuccessSolid, null],
]);
