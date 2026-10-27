<?php

use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Support\Str;

/*
| Mapa de dependencias de docs/dominio/modelo-de-datos.md: cada módulo con los que
| están inmediatamente a su izquierda (las flechas que llegan a él). Un módulo puede
| depender de esos y, transitivamente, de los que están a la izquierda de ellos.
| Una prueba de abajo lo compara con el diagrama para que no se desalineen.
*/
$leftOf = [
    'Core' => [],
    'Academic' => ['Core'],
    'Facilities' => ['Academic'],
    'Faculty' => ['Academic'],
    'Students' => ['Academic'],
    'Imports' => ['Core', 'Academic', 'Faculty', 'Facilities', 'Students'],
    'CatalogValidation' => ['Imports'],
    'Demand' => ['Students'],
    'AcademicLoad' => ['Academic', 'Demand'],
    'Staffing' => ['Faculty'],
    'Scheduling' => ['AcademicLoad', 'Facilities', 'Staffing'],
    'Solver' => ['Scheduling'],
    'Enrollment' => ['Scheduling', 'Students'],
];

$allowed = function (string $module) use (&$allowed, $leftOf): array {
    return array_values(array_unique(array_merge(
        $leftOf[$module],
        ...array_map($allowed, $leftOf[$module]),
    )));
};

$forbidden = fn (string $module): array => array_values(array_diff(array_keys($leftOf), [$module], $allowed($module)));

$namespace = fn (string $module): string => "App\\Modules\\{$module}";

$filesIn = fn (string $directory): iterable => is_dir($directory)
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS))
    : [];

$root = dirname(__DIR__, 2);

$modulesOnDisk = array_map('basename', glob("{$root}/app/Modules/*", GLOB_ONLYDIR));

test('the dependency map matches the diagram in docs/dominio/modelo-de-datos.md', function () use ($leftOf, $root) {
    $doc = file_get_contents("{$root}/docs/dominio/modelo-de-datos.md");
    preg_match('/## Mapa de dependencias entre módulos.*?```mermaid\n(.*?)```/s', $doc, $block);

    expect($block)->not->toBeEmpty('No se encontró el diagrama mermaid del mapa de dependencias.');

    $fromDoc = [];

    foreach (explode("\n", $block[1]) as $line) {
        if (! str_contains($line, '-->')) {
            continue;
        }

        $chain = preg_split('/\s*-->\s*/', trim($line));

        foreach ($chain as $i => $module) {
            $fromDoc[$module] ??= [];

            if ($i > 0) {
                $fromDoc[$module][] = $chain[$i - 1];
            }
        }
    }

    $normalize = function (array $map): array {
        ksort($map);

        return array_map(function (array $left): array {
            $left = array_values(array_unique($left));
            sort($left);

            return $left;
        }, $map);
    };

    expect($normalize($leftOf))->toBe($normalize($fromDoc));
});

test('every module folder is in the dependency map', function (string $module) use ($leftOf) {
    expect($leftOf)->toHaveKey($module);
})->with($modulesOnDisk);

test('every module has a service provider that extends ModuleServiceProvider', function (string $module) {
    $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue("Falta {$provider}.")
        ->and(is_subclass_of($provider, ModuleServiceProvider::class))->toBeTrue("{$provider} no extiende ModuleServiceProvider.");
})->with($modulesOnDisk);

test('every module service provider is registered in bootstrap/providers.php', function (string $module) use ($root) {
    $providers = require "{$root}/bootstrap/providers.php";

    expect($providers)->toContain("App\\Modules\\{$module}\\{$module}ServiceProvider");
})->with($modulesOnDisk);

// Un arch() por módulo: con varios objetivos, not->toUse() pasa en cuanto uno de ellos
// no usa la dependencia, así que no sirve para afirmar "ninguno la usa".
foreach (array_keys($leftOf) as $module) {
    arch("{$module} only depends on modules to its left in the map")
        ->expect($namespace($module))
        ->not->toUse(array_map($namespace, $forbidden($module)));

    arch("{$module} models do not read the request, session or auth")
        ->expect($namespace($module).'\\Models')
        ->not->toUse([
            'Illuminate\\Http\\Request',
            'Illuminate\\Support\\Facades\\Request',
            'Illuminate\\Support\\Facades\\Session',
            'Illuminate\\Support\\Facades\\Auth',
            'Illuminate\\Contracts\\Auth\\Guard',
            'Illuminate\\Contracts\\Auth\\StatefulGuard',
            'Illuminate\\Contracts\\Auth\\Factory',
            'request',
            'session',
            'auth',
        ]);
}

// arch() no ve archivos de rutas, migraciones anónimas, Blade ni componentes MFC (clases
// anónimas), así que se revisa el texto de toda la carpeta del módulo: namespaces (también
// en imports agrupados) y alias kebab-case (`<livewire:scheduling::…>`, `view('scheduling::…')`).
test('module files only reference modules to their left in the map', function (string $module) use ($forbidden, $filesIn, $root) {
    $forbiddenByAlias = [];

    foreach ($forbidden($module) as $other) {
        $forbiddenByAlias[Str::kebab($other)] = $other;
    }

    $violations = [];

    foreach ($filesIn("{$root}/app/Modules/{$module}") as $file) {
        $content = file_get_contents($file->getPathname());

        preg_match_all('/App\\\\+Modules\\\\+(\w+)/', $content, $byNamespace);
        preg_match_all('/App\\\\+Modules\\\\+\{([^}]*)\}/', $content, $groups);
        preg_match_all('/(?<![\w\-\\\\])([a-z][a-z0-9\-]*)::/', $content, $byAlias);
        preg_match_all('/(?:^|,)\s*(\w+)/', implode(',', $groups[1]), $grouped);

        $referenced = [
            ...$byNamespace[1],
            ...$grouped[1],
            ...array_map(fn (string $alias): ?string => $forbiddenByAlias[$alias] ?? null, $byAlias[1]),
        ];

        foreach (array_intersect(array_unique(array_filter($referenced)), $forbiddenByAlias) as $used) {
            $violations[] = substr($file->getPathname(), strlen($root) + 1)." usa {$used}";
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with(array_keys($leftOf));

// Los alias globales de fachadas (\Auth::id(), use Auth;) solo existen con la app arrancada,
// así que arch() no los ve: se buscan en los tokens de Models/.
test('module models do not use the global Auth, Request or Session aliases', function (string $module) use ($filesIn, $root) {
    $aliases = ['Auth', 'Request', 'Session'];
    $violations = [];

    foreach ($filesIn("{$root}/app/Modules/{$module}/Models") as $file) {
        $tokens = array_values(array_filter(
            PhpToken::tokenize(file_get_contents($file->getPathname())),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $i => $token) {
            $fullyQualified = $token->is(T_NAME_FULLY_QUALIFIED) && in_array(ltrim($token->text, '\\'), $aliases, true);
            $imported = $token->is(T_STRING) && in_array($token->text, $aliases, true) && ($tokens[$i - 1] ?? null)?->is(T_USE);

            if ($fullyQualified || $imported) {
                $violations[] = substr($file->getPathname(), strlen($root) + 1)." usa {$token->text}";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with(array_keys($leftOf));

$layer = fn (string $folder): array => array_map(
    fn (string $module): string => $namespace($module)."\\{$folder}",
    array_keys($leftOf),
);

arch('module actions are final')
    ->expect($layer('Actions'))
    ->classes()
    ->toBeFinal();

arch('module enums are string backed')
    ->expect($layer('Enums'))
    ->toBeStringBackedEnums();
