<?php

use Symfony\Component\Finder\Finder;

/*
| ADR-0023: Nexus nunca abre los diálogos del navegador (`confirm()`, `alert()`, `prompt()` ni `wire:confirm`); lo que
| pide confirmar usa el diálogo de Core con `x-confirm` (DESIGN.md, «Confirmar»). Antes de buscar se quitan los
| comentarios de Blade, porque los docblocks nombran lo prohibido. Deja pasar la llamada que va después de una letra,
| un punto, una flecha o una comilla: `nexusConfirm(…)`, `$wire.confirm(…)` o `wire:click="confirm(…)"` (el método
| `confirm()` del componente de CatalogValidation); lo que abre un atributo de Alpine lo atrapa la segunda expresión. El
| aviso de `beforeunload` no es una llamada y queda permitido.
*/

const NATIVE_DIALOG_CALL = '/(?<![\w.$\'">-])(window\.)?(confirm|alert|prompt)\(/';

/*
| La de arriba deja pasar lo que va justo después de una comilla, así que no ve el patrón más común en Alpine:
| `@click="confirm('¿Seguro?') && $wire.save()"`. Esta lo atrapa solo en atributos de Alpine (`x-on:*`, `@*`, `x-init`,
| `x-effect`), y por eso `wire:click="confirm(…)"` sigue pasando.
*/
const NATIVE_DIALOG_IN_ALPINE = '/(?:x-on:[\w.:-]+|@[\w.:-]+|x-init|x-effect)\s*=\s*["\']\s*(?:window\.)?(?:confirm|alert|prompt)\(/';

function callsBrowserDialog(string $code): bool
{
    return preg_match(NATIVE_DIALOG_CALL, $code) === 1 || preg_match(NATIVE_DIALOG_IN_ALPINE, $code) === 1;
}

/**
 * El código de cada vista Blade de la aplicación, sin sus comentarios de Blade, por ruta relativa.
 *
 * @return array<string, string>
 */
function bladeSourcesWithoutComments(): array
{
    $root = dirname(__DIR__, 2);
    $sources = [];

    foreach (Finder::create()->files()->in(["{$root}/app", "{$root}/resources/views"])->name('*.blade.php') as $file) {
        $sources[$file->getRelativePathname()] = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
    }

    return $sources;
}

it('ninguna vista confirma con wire:confirm', function () {
    $offenders = array_keys(array_filter(bladeSourcesWithoutComments(), fn (string $source): bool => str_contains($source, 'wire:confirm')));

    expect($offenders)->toBe([]);
});

it('ninguna vista abre confirm(), alert() ni prompt() del navegador', function () {
    $offenders = array_keys(array_filter(bladeSourcesWithoutComments(), fn (string $source): bool => callsBrowserDialog($source)));

    expect($offenders)->toBe([]);
});

it('el JS de la aplicación no abre confirm(), alert() ni prompt() del navegador', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/js')->name('*.js') as $file) {
        if (preg_match(NATIVE_DIALOG_CALL, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('la expresión atrapa las llamadas del navegador y deja pasar las de Nexus', function (string $code, bool $caught) {
    expect(callsBrowserDialog($code))->toBe($caught);
})->with([
    'confirm() en línea' => ['x-on:click="if (confirm(\'¿Seguro?\')) $wire.save()"', true],
    'confirm() al inicio de @click' => ['@click="confirm(\'¿Seguro?\') && $wire.save()"', true],
    'confirm() al inicio de x-on:click' => ['x-on:click="confirm(\'x\') || $wire.cancel()"', true],
    'window.alert()' => ['window.alert("Listo")', true],
    'prompt()' => ['const name = prompt("Nombre")', true],
    'el método confirm() de Livewire' => ['wire:click="confirm(\'validate\', \'subjects\')"', false],
    '$wire.confirm()' => ['$wire.confirm()', false],
    'nexusConfirm()' => ['window.nexusConfirm({ title: "¿Seguro?" })', false],
    'un método de PHP' => ['$this->confirm("¿Seguir?")', false],
]);
