<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\RecipientsQuery;
use Symfony\Component\Finder\Finder;

/*
| ADR-0018: la persona en la petición es `Actor` (Core/Authorization) y las Policies llegan a ella por
| `AuthorizesActor`. Los nombres anteriores se retiraron; nadie debe volver a escribirlos. En 3.P9 salió también la API
| de compatibilidad por rol (has, covers…) y RecipientsQuery::for(): se pregunta por un permiso.
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

it('el Actor no conserva la API de compatibilidad', function () {
    $retired = array_values(array_filter(
        ['has', 'covers', 'coversWithScope', 'coversDepartment', 'canAccessCampus', 'scopes'],
        fn (string $method): bool => method_exists(Actor::class, $method),
    ));

    expect($retired)->toBe([])
        ->and((new ReflectionMethod(Actor::class, 'isAdmin'))->isPublic())->toBeFalse();
});

it('RecipientsQuery no conserva for()', function () {
    expect(method_exists(RecipientsQuery::class, 'for'))->toBeFalse();
});
