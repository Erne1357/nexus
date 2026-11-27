<?php

use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Faculty\Database\Seeders\DemoTeacherEmail;
use Illuminate\Support\Facades\DB;

/*
| La demo por puntos con los pasos reales de los módulos y el volumen demo: llega al último punto pasando por cada uno,
| y al llegar a cada uno todas las revisiones que declaran los módulos (demoChecks) pasan. Un punto sin revisiones todavía
| pasa sin más: cada riel agrega las suyas con sus pasos (docs/arquitectura/testing.md, «Demo por puntos del proceso»).
| Corre con el dominio institucional de la demo (DemoTeacherEmail), como se siembra y se sirve la demo, para que los
| docentes traigan correo institucional y los avisos de la republicación (RA-06) salgan también por correo.
*/

it('llega a cada punto con todas sus revisiones en verde', function () {
    config(['nexus.institutional_email_domain' => DemoTeacherEmail::DOMAIN]);
    $reached = [];
    $failures = [];

    $last = DemoCheckpoint::staged()[count(DemoCheckpoint::staged()) - 1];

    $report = app(DemoRunner::class)->run(
        $last,
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
        ->and($reached)->toBe(DemoCheckpoint::staged())
        ->and($report->checks)->not->toBeEmpty();

    // E-04 del ensayo 8.11: casi todo docente trae su correo institucional ficticio y la republicación les escribe ahí.
    $teachers = DB::table('teachers')->get(['rfc', 'institutional_email']);
    $withEmail = $teachers->whereNotNull('institutional_email');

    expect($withEmail->count())->toBeGreaterThanOrEqual((int) ceil(0.8 * $teachers->count()))
        ->and($withEmail->every(fn (object $teacher): bool => $teacher->institutional_email === mb_strtolower($teacher->rfc).'@'.DemoTeacherEmail::DOMAIN))->toBeTrue()
        ->and(DB::table('mail_log')->where('kind', 'LoadRepublished')->where('to', 'like', '%@'.DemoTeacherEmail::DOMAIN)->exists())->toBeTrue();
});
