<?php

use App\Modules\Core\ModuleServiceProvider;

/*
| Mapa de dependencias de docs/dominio/modelo-de-datos.md: cada módulo con los que
| están inmediatamente a su izquierda (las flechas que llegan a él). Un módulo puede
| depender de esos y, transitivamente, de los que están a la izquierda de ellos.
| Si cambia el diagrama, cambia este arreglo en el mismo PR.
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

$namespace = fn (string $module): string => "App\\Modules\\{$module}";

$modulesOnDisk = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR));

test('every module folder is in the dependency map', function (string $module) use ($leftOf) {
    expect($leftOf)->toHaveKey($module);
})->with($modulesOnDisk);

test('every module has a service provider that extends ModuleServiceProvider', function (string $module) {
    $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue("Falta {$provider}.")
        ->and(is_subclass_of($provider, ModuleServiceProvider::class))->toBeTrue("{$provider} no extiende ModuleServiceProvider.");
})->with($modulesOnDisk);

test('every module service provider is registered in bootstrap/providers.php', function (string $module) {
    $providers = require dirname(__DIR__, 2).'/bootstrap/providers.php';

    expect($providers)->toContain("App\\Modules\\{$module}\\{$module}ServiceProvider");
})->with($modulesOnDisk);

// Un arch() por módulo: con varios objetivos, not->toUse() pasa en cuanto uno de ellos
// no usa la dependencia, así que no sirve para afirmar "ninguno la usa".
foreach (array_keys($leftOf) as $module) {
    $forbidden = array_diff(array_keys($leftOf), [$module], $allowed($module));

    arch("{$module} only depends on modules to its left in the map")
        ->expect($namespace($module))
        ->not->toUse(array_map($namespace, array_values($forbidden)));

    arch("{$module} models do not read the request, session or auth")
        ->expect($namespace($module).'\\Models')
        ->not->toUse([
            'Illuminate\\Http\\Request',
            'Illuminate\\Support\\Facades\\Request',
            'Illuminate\\Support\\Facades\\Session',
            'Illuminate\\Support\\Facades\\Auth',
            'request',
            'session',
            'auth',
        ]);
}

// arch() ignora las clases anónimas, así que no ve los componentes MFC: se revisa el texto
// de Livewire/ (.php y .blade.php) buscando referencias a módulos fuera del mapa.
test('module Livewire components only depend on modules to their left in the map', function (string $module) use ($leftOf, $allowed) {
    $forbidden = array_diff(array_keys($leftOf), [$module], $allowed($module));
    $livewire = dirname(__DIR__, 2)."/app/Modules/{$module}/Livewire";
    $violations = [];

    $files = is_dir($livewire) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($livewire, FilesystemIterator::SKIP_DOTS)) : [];

    foreach ($files as $file) {
        preg_match_all('/App\\\\Modules\\\\(\w+)/', file_get_contents($file->getPathname()), $matches);

        foreach (array_intersect(array_unique($matches[1]), $forbidden) as $used) {
            $violations[] = "{$file->getPathname()} usa App\\Modules\\{$used}";
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
