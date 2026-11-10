<?php

use Symfony\Component\Finder\Finder;

/*
| Las funciones auxiliares de un archivo de Pest son globales: dos archivos que declaran `function x()` revientan la
| suite completa («Cannot redeclare function x()»), aunque cada archivo pase solo. Por eso cada auxiliar lleva el
| nombre de su recurso (`newCampus`, `semesterBoardGroup`) y esta prueba lo revisa en cada corrida de tests/Arch.
*/
it('no repite el nombre de una función auxiliar global entre archivos de prueba', function () {
    $seen = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__))->name('*.php') as $file) {
        preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $file->getContents(), $matches);

        foreach ($matches[1] as $name) {
            $seen[strtolower($name)][] = $file->getRelativePathname();
        }
    }

    $repeated = array_filter($seen, fn (array $files): bool => count($files) > 1);

    expect(array_map(fn (array $files): string => implode(', ', $files), $repeated))->toBe([]);
});
