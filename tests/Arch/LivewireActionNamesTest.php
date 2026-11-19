<?php

use Symfony\Component\Finder\Finder;

/*
| E-01 (ensayo 8.11): `$wire` de Livewire 4 resuelve algunos nombres como su propia API de JavaScript y no como un
| método del componente. `wire:submit="upload"` llamaba a `$wire.$upload` (subir un archivo) en lugar del método
| `upload()` del asistente de importación, y «Validar el archivo» no hacía nada. Ningún componente declara un método
| público con uno de esos nombres. Tampoco una propiedad pública: `$wire` resuelve los alias antes que el estado, así que
| `$wire.errors` nunca leería una propiedad `$errors`. La lista sale del mapa `aliases` de `livewire.js`, así que una
| versión de Livewire que agregue otro alias la revisa sola.
*/

/**
 * Los nombres que `$wire` resuelve como su API (`upload` → `$upload`), leídos del mapa `aliases` de livewire.js.
 *
 * @return list<string>
 */
function livewireWireAliases(): array
{
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/vendor/livewire/livewire/dist/livewire.js');

    // esbuild puede renombrar el identificador (`aliases2`) y otra versión puede declararlo con let o const.
    preg_match('/(?:var|let|const)\s+aliases\d*\s*=\s*\{(.*?)\};/s', $script, $block);
    preg_match_all('/"(\w+)":\s*"\$\w+"/', $block[1] ?? '', $names);

    return $names[1];
}

/**
 * Los tokens, sin espacios ni comentarios, de cada componente MFC de los módulos y de cada trait de Livewire que se les
 * mezcla, por ruta relativa.
 *
 * @return array<string, list<PhpToken>>
 */
function livewireComponentTokens(): array
{
    $root = dirname(__DIR__, 2);
    $tokens = [];

    $files = Finder::create()->files()->in("{$root}/app/Modules/*/Livewire")
        ->path('/^(pages|components|Concerns)\//')
        ->name('*.php')
        ->notName('*.blade.php');

    foreach ($files as $file) {
        $tokens[substr($file->getPathname(), strlen($root) + 1)] = array_values(array_filter(PhpToken::tokenize($file->getContents()), fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    return $tokens;
}

/**
 * Los métodos públicos que declara cada componente MFC de los módulos, y cada trait de Livewire que se les mezcla, por
 * ruta relativa. Un método sin visibilidad también es público.
 *
 * @return array<string, list<string>>
 */
function livewirePublicMethods(): array
{
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
    $methods = [];

    foreach (livewireComponentTokens() as $path => $tokens) {

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
                $methods[$path][] = $name->text;
            }
        }
    }

    return $methods;
}

/**
 * Las propiedades públicas que declara cada componente y cada trait, por ruta relativa, sin el `$`: lo que sigue a
 * `public` (con su tipo, `readonly` o `static`) cuando llega a una variable y no a `function` ni a `const`.
 *
 * @return array<string, list<string>>
 */
function livewirePublicProperties(): array
{
    $typeTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE, T_STATIC, T_READONLY, '?', '|', '(', ')', T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];
    $properties = [];

    foreach (livewireComponentTokens() as $path => $tokens) {
        foreach ($tokens as $index => $token) {
            if (! $token->is(T_PUBLIC)) {
                continue;
            }

            for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next]->is($typeTokens); $next++) {
                // El tipo y los modificadores de la propiedad.
            }

            if (isset($tokens[$next]) && $tokens[$next]->is(T_VARIABLE)) {
                $properties[$path][] = substr($tokens[$next]->text, 1);
            }
        }
    }

    return $properties;
}

it('lee los alias de $wire de livewire.js', function () {
    $aliases = livewireWireAliases();

    expect(array_intersect(['upload', 'dispatch', 'commit', 'errors'], $aliases))
        ->toHaveCount(4, 'No se encontró el mapa aliases en vendor/livewire/livewire/dist/livewire.js: revisa el patrón de livewireWireAliases() contra la versión instalada.');
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

it('ningún componente Livewire declara una propiedad pública que $wire resuelve como su API', function () {
    $aliases = livewireWireAliases();
    $offenders = [];

    foreach (livewirePublicProperties() as $path => $properties) {
        foreach (array_intersect($properties, $aliases) as $property) {
            $offenders[] = "{$path}: \${$property}";
        }
    }

    expect($offenders)->toBe([]);
});

it('encuentra las propiedades públicas de los componentes, con su tipo o sin él', function () {
    expect(livewirePublicProperties()['app/Modules/Imports/Livewire/pages/import-wizard/import-wizard.php'] ?? [])
        ->toContain('step', 'template', 'file', 'header', 'batchId')
        ->not->toContain('templates', 'batches');
});

it('encuentra los métodos públicos de los componentes, también los de sus traits', function () {
    $methods = livewirePublicMethods();

    expect($methods['app/Modules/Imports/Livewire/pages/import-wizard/import-wizard.php'] ?? [])
        ->toContain('mount', 'continue', 'apply', 'requeue')
        ->not->toContain('importCampus', 'discardUpload')
        ->and(array_merge(...array_values(array_filter($methods, fn (string $path): bool => str_contains($path, '/Concerns/'), ARRAY_FILTER_USE_KEY))))
        ->not->toBe([]);
});
