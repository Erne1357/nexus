<?php

use Symfony\Component\Finder\Finder;

/*
| Un comentario de Blade que abre con `{{--` y cierra con `--}` (una llave de menos) no se cierra: su texto
| sale en la página. Cada `{{--` lleva su `--}}` y no queda un `--}` suelto.
*/
it('cierra cada comentario de Blade con --}} y no deja un --} suelto', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in([dirname(__DIR__, 2).'/app', dirname(__DIR__, 2).'/resources/views'])->name('*.blade.php') as $file) {
        $source = $file->getContents();
        $opens = substr_count($source, '{{--');
        $closes = substr_count($source, '--}}');
        $loose = preg_match_all('/--\}(?!\})/', $source);

        if ($opens !== $closes || $loose > 0) {
            $offenders[] = $file->getRelativePathname()." ({$opens} aperturas, {$closes} cierres, {$loose} --} sueltos)";
        }
    }

    expect($offenders)->toBe([]);
});
