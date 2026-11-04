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

/*
| ADR-0018 (3.P9): quien decide es el motor (`Actor::allows()`), no el puesto. `Role::` solo se nombra en `grants()` de
| los providers y en las piezas que nombran puestos por oficio (nombrar, listar, sembrar, mostrar). Cada archivo de la
| lista debe seguir necesitando su entrada.
*/
const ROLE_NAMING_EXCEPTIONS = [
    'app/Modules/Academic/Database/Seeders/DemoStaffSeeder.php',
    'app/Modules/CatalogValidation/Database/Seeders/CatalogValidationDemoSeeder.php',
    'app/Modules/Core/Actions/EndRoleAssignment.php',
    'app/Modules/Core/Database/Factories/RoleAssignmentFactory.php',
    'app/Modules/Core/Database/Factories/UserFactory.php',
    'app/Modules/Core/Database/Seeders/AdminUserSeeder.php',
    'app/Modules/Core/Livewire/Concerns/AppointsRoles.php',
    'app/Modules/Core/Livewire/components/context-switcher/context-switcher.php',
    'app/Modules/Core/Livewire/components/org-unit-panel/org-unit-panel.php',
    'app/Modules/Core/Livewire/pages/people/people.php',
    'app/Modules/Core/Livewire/pages/person/person.php',
    'app/Modules/Core/Notifications/NotificationMessage.php',
    'app/Modules/Core/Queries/OrgChartQuery.php',
    'app/Modules/Core/Rules/RoleAssignmentRules.php',
    'app/Modules/Faculty/Actions/SyncTeacherAccess.php',
    'app/Modules/Faculty/Database/Seeders/FacultyDemoSeeder.php',
    'app/Modules/Faculty/Livewire/pages/teacher/teacher.blade.php',
    'app/Modules/Faculty/Livewire/pages/teacher/teacher.php',
    'app/Modules/Faculty/Queries/TeacherRecordQuery.php',
];

/**
 * Los aciertos de `Role::` (distinto de `Role::class`) fuera de Core/Authorization, como `ruta => [líneas]`. Los de un
 * `*ServiceProvider` que caen dentro de `grants()` no cuentan.
 *
 * @return array<string, list<int>>
 */
function roleReferences(): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach (Finder::create()->files()->name('*.php')->in(["{$root}/app", "{$root}/database", "{$root}/resources", "{$root}/routes"]) as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);

        if (str_starts_with($path, 'app/Modules/Core/Authorization/')) {
            continue;
        }

        $source = $file->getContents();
        $lines = [];

        if (str_ends_with($path, '.blade.php')) {
            $clean = preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $source);

            foreach (explode("\n", $clean) as $index => $line) {
                if (preg_match('/(?<![\w\\\\])(?:\\\\?App\\\\Modules\\\\Core\\\\Enums\\\\)?Role::(?!class\b)/', $line) === 1) {
                    $lines[] = $index + 1;
                }
            }
        } else {
            $tokens = array_values(array_filter(
                PhpToken::tokenize($source),
                fn (PhpToken $token): bool => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $index => $token) {
                $isRole = $token->is(T_STRING) && $token->text === 'Role'
                    || $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && str_ends_with($token->text, '\\Role');
                $next = $tokens[$index + 1] ?? null;
                $after = $tokens[$index + 2] ?? null;

                if ($isRole && $next?->is(T_PAAMAYIM_NEKUDOTAYIM) && ! ($after?->is(T_CLASS))) {
                    $lines[] = $token->line;
                }
            }
        }

        if ($lines !== [] && str_ends_with($path, 'ServiceProvider.php')) {
            $class = preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1 && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $name) === 1
                ? $ns[1].'\\'.$name[1]
                : null;

            if ($class !== null && method_exists($class, 'grants')) {
                $method = new ReflectionMethod($class, 'grants');
                $lines = array_values(array_filter(
                    $lines,
                    fn (int $line): bool => $line < $method->getStartLine() || $line > $method->getEndLine(),
                ));
            }
        }

        if ($lines !== []) {
            $found[$path] = $lines;
        }
    }

    return $found;
}

it('nada fuera de Core/Authorization decide con Role::, salvo grants() de los providers y las piezas que nombran puestos por oficio', function () {
    $offenders = [];

    foreach (roleReferences() as $path => $lines) {
        if (in_array($path, ROLE_NAMING_EXCEPTIONS, true)) {
            continue;
        }

        foreach ($lines as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([]);
});

it('cada excepción todavía nombra puestos con Role::', function () {
    $found = roleReferences();

    expect(array_values(array_filter(
        ROLE_NAMING_EXCEPTIONS,
        fn (string $path): bool => ! isset($found[$path]),
    )))->toBe([]);
});

it('nadie importa el enum Role con otro nombre', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (Finder::create()->files()->in(["{$root}/app", "{$root}/database", "{$root}/resources", "{$root}/routes"]) as $file) {
        if (str_contains($file->getContents(), 'Enums\\Role as ')) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($offenders)->toBe([]);
});
