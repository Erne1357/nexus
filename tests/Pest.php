<?php

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/*
| Feature y Browser corren con la aplicación de Laravel arrancada y la base de
| pruebas refrescada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

/*
| Los browser tests cargan el CSS y el JS compilados (`npm run build`), porque la banda, la credencial
| y el menú no funcionan sin ellos (TestCase los quita para el resto). Usan siempre el manifiesto,
| aunque corra el servidor de desarrollo de Vite: Chromium, dentro del contenedor, no llega a él.
*/
pest()->beforeEach(function () {
    $this->withVite();
    app(Vite::class)->useHotFile(storage_path('framework/testing/vite-hot-ignorado'));
})->in('Browser');

/**
 * El último toast que el componente mandó mostrar: MaryUI lo evalúa en el navegador como `toast({...})`.
 *
 * @return array{type: string, title: string, description: ?string}|null
 */
function lastToastOf(Testable $component): ?array
{
    $expression = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->last(fn (string $expression): bool => str_starts_with($expression, 'toast('));

    return $expression === null ? null : json_decode(substr($expression, 6, -1), true)['toast'];
}

/**
 * Un uuid fijo para un registro que la prueba no crea: `fakeKey(10)` siempre da el mismo valor. Lo
 * usan los departamentos y carreras de los alcances mientras Academic no tenga sus tablas (fase 1).
 */
function fakeKey(int $number): string
{
    return sprintf('00000000-0000-7000-8000-%012d', $number);
}

/**
 * El HTML de una respuesta o de un componente como documento, para afirmar dentro de una parte de la
 * página (la banda, la credencial, el menú) con selectores CSS.
 */
function domOf(TestResponse|Testable|string $html): HTMLDocument
{
    $html = match (true) {
        $html instanceof TestResponse => (string) $html->getContent(),
        $html instanceof Testable => $html->html(),
        default => $html,
    };

    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * El texto de un elemento con los espacios colapsados; cadena vacía si el elemento no existe.
 */
function textOf(?Element $element): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $element?->textContent ?? ''));
}
