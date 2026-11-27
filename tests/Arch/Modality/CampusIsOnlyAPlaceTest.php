<?php

use Symfony\Component\Finder\Finder;

/*
| MS3 (supuesto por confirmar): el campus es solo el lugar (`code`, `name`, `is_active`), sin modalidad ni perfil de
| flujo; el perfil es de la modalidad (`modalities.settings`, MS1). Al contraer la carga por modalidad (ADR-0025) se
| quitaron el perfil del campus (CampusProfile, `campuses.settings`), su modalidad (`campuses.modality`, el enum
| Core\Enums\Modality) y el puente al catálogo (`campuses.modality_id`, Campus::catalogModality(),
| Modality::catalogCode()), y el puente Actor::activeCampus() de las pantallas (#415): cada pantalla lee su campus con
| WithCampusFilter o WithModalityCampusFilter. Estas pruebas buscan en el código de app/ y resources/, también en los
| comentarios, para que nadie los vuelva a usar. Las migraciones quedan fuera: son historia y nombran lo que había.
*/

/** Una lectura del campus que ya no existe: su perfil, su modalidad o el puente (`$campus->modality_id`, `->campus->profile`). */
const CAMPUS_BRIDGE_READ = '/(\$\w*campus\w*|->campus)\??->(settings|profile|modality|modality_id|catalogModality)\b/i';

/**
 * El código de app/ y resources/ (PHP y Blade), sin las migraciones, por ruta relativa a la raíz.
 *
 * @return array<string, string>
 */
function sourcesOutsideMigrations(): array
{
    $root = dirname(__DIR__, 3);
    $sources = [];

    foreach (Finder::create()->files()->in(["{$root}/app", "{$root}/resources"])->name('*.php')->notPath('#Database/Migrations/#') as $file) {
        $sources[substr($file->getRealPath(), strlen($root) + 1)] = $file->getContents();
    }

    return $sources;
}

/**
 * Las rutas cuyo código cumple la expresión.
 *
 * @return list<string>
 */
function sourcesMatching(string $pattern): array
{
    return array_keys(array_filter(sourcesOutsideMigrations(), fn (string $source): bool => preg_match($pattern, $source) === 1));
}

it('nadie usa el puente Actor::activeCampus() (#415): el campus de una pantalla es su filtro', function () {
    expect(sourcesMatching('/\bactiveCampus\b/'))->toBe([]);
});

it('MS3: nada usa el perfil de flujo de un campus (CampusProfile)', function () {
    expect(file_exists(dirname(__DIR__, 3).'/app/Modules/Core/Models/CampusProfile.php'))->toBeFalse()
        ->and(sourcesMatching('/\bCampusProfile\b/'))->toBe([]);
});

it('MS3: nada lee `settings`, `profile`, `modality` ni `modality_id` de un campus', function () {
    expect(sourcesMatching(CAMPUS_BRIDGE_READ))->toBe([]);
});

it('la búsqueda de lecturas del campus atrapa las formas comunes y no confunde las de una carga o un grupo', function () {
    $caught = ['$campus->settings', '$campus?->profile', '$this->campus->modality', '$room->campus->modality_id', '$campusModel->modality_id'];
    $allowed = ['$load->modality', '$group->modality_id', '$this->campus', '$campuses->first()', '$modality->profile', 'zeroInactiveCampus($x)'];

    foreach ($caught as $code) {
        expect(preg_match(CAMPUS_BRIDGE_READ, $code))->toBe(1, $code);
    }

    foreach ($allowed as $code) {
        expect(preg_match(CAMPUS_BRIDGE_READ, $code))->toBe(0, $code);
    }
});

it('MS1: la modalidad es el catálogo de Core, no el enum Core\Enums\Modality ni el puente de campus', function () {
    expect(file_exists(dirname(__DIR__, 3).'/app/Modules/Core/Enums/Modality.php'))->toBeFalse()
        ->and(sourcesMatching('/Core\\\\Enums\\\\Modality\b|Core\\\\Enums\\\\\{[^}]*\bModality\b/'))->toBe([])
        ->and(sourcesMatching('/\bcatalogModality\b|\bcatalogCode\b/'))->toBe([]);
});
