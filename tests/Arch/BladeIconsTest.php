<?php

use Symfony\Component\Finder\Finder;

/*
| MaryUI pone w-5 h-5 a todo <x-icon> cuya clase no tenga un w- o un h-, y esas utilidades le ganan a
| size-*: un icono con size-4 se pinta de 20 px y se sale de su recuadro. Por eso el tamaño de un icono
| va con w-* y h-*.
*/
it('da el tamaño de cada x-icon con w-* y h-*, no con size-*', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in([dirname(__DIR__, 2).'/app', dirname(__DIR__, 2).'/resources/views'])->name('*.blade.php') as $file) {
        preg_match_all('/<x-icon\b(?:[^"\'>]|"[^"]*"|\'[^\']*\')*>/', $file->getContents(), $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/\sclass="[^"]*(?<![\w-])size-/', $tag) === 1) {
                $offenders[] = $file->getRelativePathname().': '.$tag;
            }
        }
    }

    expect($offenders)->toBe([]);
});
