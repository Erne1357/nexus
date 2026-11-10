<?php

use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;

/*
| La demo por puntos con los pasos reales de los módulos y el volumen demo: llega al cierre pasando por cada punto, y al
| llegar a cada uno todas las revisiones que declaran los módulos (demoChecks) pasan. Un punto sin revisiones todavía
| pasa sin más: cada riel agrega las suyas con sus pasos (docs/arquitectura/testing.md, «Demo por puntos del proceso»).
*/

it('llega a cada punto con todas sus revisiones en verde', function () {
    $reached = [];
    $failures = [];

    $report = app(DemoRunner::class)->run(
        DemoCheckpoint::Closing,
        DemoContext::for(DemoVolume::Demo),
        function (DemoCheckpoint $checkpoint, array $results) use (&$reached, &$failures): void {
            $reached[] = $checkpoint;

            foreach ($results as $result) {
                if (! $result['passed']) {
                    $failures[] = "{$checkpoint->value}: {$result['label']} ({$result['reason']})";
                }
            }
        },
    );

    expect($failures)->toBe([])
        ->and($reached)->toBe(DemoCheckpoint::upTo(DemoCheckpoint::Closing))
        ->and($report->checks)->not->toBeEmpty();
});
