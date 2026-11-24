<?php

use Tests\Support\MigrationSafetyScanner;

/*
| ADR-0033: durante un despliegue conviven el código nuevo y el anterior sobre la misma base. Toda migración nueva
| debe ser compatible con el release anterior (expand/contract). La línea base son las migraciones que ya existían
| al crear el riel de despliegue (1.31): no se revisan. Agregar nombres a la línea base no es una salida.
*/

/** Nombre de archivo => ruta de cada migración del proyecto (módulos y `database/migrations`). */
function migrationSafetyFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (array_merge(glob("{$root}/app/Modules/*/Database/Migrations/*.php") ?: [], glob("{$root}/database/migrations/*.php") ?: []) as $path) {
        $files[basename($path)] = $path;
    }

    return $files;
}

/** @return list<string> */
function migrationSafetyBaseline(): array
{
    return array_values(array_filter(array_map('trim', file(__DIR__.'/migrations-baseline.txt'))));
}

it('toda migración nueva es compatible con el release anterior', function () {
    $baseline = migrationSafetyBaseline();
    $scanner = new MigrationSafetyScanner;
    $problems = [];

    foreach (migrationSafetyFiles() as $name => $path) {
        if (in_array($name, $baseline, true)) {
            continue;
        }

        $violations = $scanner->scan((string) file_get_contents($path));

        if ($violations !== []) {
            $problems[] = $name."\n  - ".implode("\n  - ", $violations);
        }
    }

    expect($problems)->toBe([], "Migraciones que rompen al release anterior:\n".implode("\n", $problems)
        ."\nSepara la contracción en un release posterior y márcala con #[Contract('…')] (ADR-0033).");
});

it('la línea base no crece', function () {
    $baseline = migrationSafetyBaseline();
    $files = migrationSafetyFiles();

    expect(array_values(array_diff($baseline, array_keys($files))))->toBe([], 'Una migración de la línea base dejó de existir.')
        ->and($baseline)->toHaveCount(MIGRATION_SAFETY_BASELINE_SIZE, 'La línea base no se amplía: una migración nueva debe pasar la guardia.')
        ->and($baseline)->toBe(array_values(array_unique($baseline)));
});

const MIGRATION_SAFETY_BASELINE_SIZE = 151;
