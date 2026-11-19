<?php

use Symfony\Component\Finder\Finder;

/*
| E-01 (ensayo 8.11): `$wire` de Livewire 4 resuelve algunos nombres como su propia API de JavaScript y no como un
| método del componente. `wire:submit="upload"` llamaba a `$wire.$upload` (subir un archivo) en lugar del método
| `upload()` del asistente de importación, y «Validar el archivo» no hacía nada. Ningún componente declara un método
| público con uno de esos nombres. La lista sale del mapa `aliases` de `livewire.js`, así que una versión de Livewire que
| agregue otro alias la revisa sola.
*/

/**
 * Los nombres que `$wire` resuelve como su API (`upload` → `$upload`), leídos del mapa `aliases` de livewire.js.
 *
 * @return list<string>
 */
function livewireWireAliases(): array
{
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/vendor/livewire/livewire/dist/livewire.js');

    preg_match('/var aliases = \{(.*?)\};/s', $script, $block);
    preg_match_all('/"(\w+)":\s*"\$\w+"/', $block[1] ?? '', $names);

    return $names[1];
}

/**
 * Los métodos públicos que declara cada componente MFC de los módulos, y cada trait de Livewire que se les mezcla, por
 * ruta relativa. Un método sin visibilidad también es público.
 *
 * @return array<string, list<string>>
 */
function livewirePublicMethods(): array
{
    $root = dirname(__DIR__, 2);
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
    $methods = [];

    $files = Finder::create()->files()->in("{$root}/app/Modules/*/Livewire")
        ->path('/^(pages|components|Concerns)\//')
        ->name('*.php')
        ->notName('*.blade.php');

    foreach ($files as $file) {
        $tokens = array_values(array_filter(PhpToken::tokenize($file->getContents()), fn (PhpToken $token): bool => ! $token->isIgnorable()));

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_FUNCTION)) {
                continue;
            }

            $nameAt = $index + 1 + (int) ($tokens[$index + 1] ?? null)?->is('&');
            $name = $tokens[$nameAt] ?? null;

            // Una closure (`function (`) no tiene nombre.
            if ($name === null || preg_match('/^\w+$/', $name->text) !== 1 || ! ($tokens[$nameAt + 1] ?? null)?->is('(')) {
                continue;
            }

            $visibility = [];

            for ($back = $index - 1; $back >= 0 && $tokens[$back]->is($modifiers); $back--) {
                $visibility[] = $tokens[$back]->id;
            }

            if (! in_array(T_PROTECTED, $visibility, true) && ! in_array(T_PRIVATE, $visibility, true)) {
                $methods[substr($file->getPathname(), strlen($root) + 1)][] = $name->text;
            }
        }
    }

    return $methods;
}

it('lee los alias de $wire de livewire.js', function () {
    expect(livewireWireAliases())->toContain('upload', 'dispatch', 'commit', 'errors');
});

it('ningún componente Livewire declara un método público que $wire resuelve como su API', function () {
    $aliases = livewireWireAliases();
    $offenders = [];

    foreach (livewirePublicMethods() as $path => $methods) {
        foreach (array_intersect($methods, $aliases) as $method) {
            $offenders[] = "{$path}: {$method}()";
        }
    }

    expect($offenders)->toBe([]);
});

it('encuentra los métodos públicos de los componentes, también los de sus traits', function () {
    $methods = livewirePublicMethods();

    expect($methods['app/Modules/Imports/Livewire/pages/import-wizard/import-wizard.php'] ?? [])
        ->toContain('mount', 'continue', 'apply', 'requeue')
        ->not->toContain('importCampus', 'discardUpload')
        ->and(array_merge(...array_values(array_filter($methods, fn (string $path): bool => str_contains($path, '/Concerns/'), ARRAY_FILTER_USE_KEY))))
        ->not->toBe([]);
});
