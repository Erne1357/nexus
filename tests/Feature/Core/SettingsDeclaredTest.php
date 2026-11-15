<?php

use App\Modules\Core\Settings\SettingRegistry;

/*
| CF-S7: toda llave de `settings` que un módulo lee está declarada en settings() de su provider; si no, el admin no la
| vería en «Parámetros». Se buscan las llaves que el código lee: las constantes `*SETTING` (las que pasan a
| `Setting::query()`) y los `->setting('modulo.llave')` del estado de horarios.
*/

/**
 * @return array<string, list<string>> llave => archivos donde se lee
 */
function settingKeysRead(): array
{
    $keys = [];
    $root = base_path('app/Modules');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || str_contains($path, '/Database/Seeders/') || str_contains($path, '/Demo/')) {
            continue;
        }

        $code = file_get_contents($path);
        preg_match_all('/const\s+(?:string\s+)?[A-Z_]*SETTING\s*=\s*\'([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\'/', $code, $constants);
        preg_match_all('/->setting\(\s*\'([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\'/', $code, $calls);

        foreach ([...$constants[1], ...$calls[1]] as $key) {
            $keys[$key][] = substr($path, strlen($root) + 1);
        }
    }

    return $keys;
}

it('CF-S7: toda llave de parámetro que leen los módulos está declarada en settings() de un provider', function () {
    $registry = app(SettingRegistry::class);
    $missing = [];

    foreach (settingKeysRead() as $key => $files) {
        if ($registry->find($key) === null) {
            $missing[] = $key.' ('.implode(', ', array_unique($files)).')';
        }
    }

    expect(settingKeysRead())->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('CF-S7: todo archivo que lee la tabla settings con Setting::query() trae al menos una llave que la prueba reconoce', function () {
    $root = base_path('app/Modules');
    $known = array_merge(...array_values(settingKeysRead()));
    $unknown = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        $relative = substr($path, strlen($root) + 1);

        if (! str_ends_with($path, '.php') || str_contains($path, '/Database/Seeders/') || str_contains($path, '/Demo/') || str_starts_with($relative, 'Core/')) {
            continue;
        }

        $code = file_get_contents($path);

        // ScheduleStateQuery lee por prefijo todo lo de `staffing.` y `scheduling.`; sus llaves son las de los `->setting()`.
        if (str_contains($code, 'Setting::query()') && ! str_contains($code, 'SETTING_PREFIXES') && ! in_array($relative, $known, true)) {
            $unknown[] = $relative;
        }
    }

    expect($unknown)->toBe([]);
});
