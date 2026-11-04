<?php

use Symfony\Component\Finder\Finder;

/*
| ADR-0018: la persona en la petición es `Actor` (Core/Authorization) y las Policies llegan a ella por
| `AuthorizesActor`. Los nombres anteriores se retiraron; nadie debe volver a escribirlos.
*/
it('nada en el código ni en las pruebas nombra ScopedContext ni UsesScopedContext', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (Finder::create()->files()->in(["{$root}/app", "{$root}/database", "{$root}/resources", "{$root}/routes", "{$root}/tests"]) as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);

        if ($path === 'tests/Arch/AuthorizationTest.php') {
            continue;
        }

        if (str_contains($file->getContents(), 'ScopedContext')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
