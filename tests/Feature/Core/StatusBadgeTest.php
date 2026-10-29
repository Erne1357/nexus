<?php

use App\Modules\Core\Enums\StatusTone;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\View\ViewException;

/*
| Insignia de estado `<x-core::status-badge>` (DESIGN.md, "Insignias de estado"): la misma en toda
| la aplicación, con las clases y el icono de su tono. El color nunca va solo: siempre lleva el texto,
| y el icono es decorativo (aria-hidden) porque el texto ya dice el estado.
*/

/**
 * La insignia renderizada, como elemento del DOM para revisar sus clases, su texto y su icono.
 */
function renderStatusBadge(string $template, array $data = []): Element
{
    $html = (string) test()->blade($template, $data);

    return HTMLDocument::createFromString("<!DOCTYPE html><body>{$html}</body>", LIBXML_NOERROR)
        ->querySelector('.badge');
}

/**
 * El contenido del svg de un icono de Heroicons, para saber qué icono pintó la insignia.
 */
function heroiconContents(string $icon): string
{
    $svg = HTMLDocument::createFromString('<!DOCTYPE html><body>'.svg("heroicon-{$icon}")->toHtml().'</body>', LIBXML_NOERROR)
        ->querySelector('svg');

    return $svg->innerHTML;
}

it('pinta el texto, las clases y el icono de su tono', function (StatusTone $tone) {
    $badge = renderStatusBadge('<x-core::status-badge :tone="$tone" label="Activo" />', ['tone' => $tone]);

    expect(trim($badge->textContent))->toBe('Activo')
        ->and($badge->classList->contains('badge'))->toBeTrue();

    foreach (explode(' ', $tone->classes()) as $class) {
        expect($badge->classList->contains($class))->toBeTrue("Falta la clase {$class}.");
    }

    $icon = $badge->querySelector('svg');

    if ($tone->icon() === null) {
        expect($icon)->toBeNull();
    } else {
        expect($icon->innerHTML)->toBe(heroiconContents($tone->icon()));
    }
})->with(StatusTone::cases());

it('esconde el icono a los lectores de pantalla: el texto ya dice el estado', function () {
    $badge = renderStatusBadge('<x-core::status-badge tone="success" label="Validado" />');

    expect($badge->querySelector('svg')->getAttribute('aria-hidden'))->toBe('true');
});

it('acepta el tono como texto y otro icono en lugar del del tono', function () {
    $badge = renderStatusBadge('<x-core::status-badge tone="warning" label="Con advertencia" icon="o-exclamation-triangle" />');

    expect($badge->classList->contains('badge-warning'))->toBeTrue()
        ->and($badge->querySelector('svg')->innerHTML)->toBe(heroiconContents('o-exclamation-triangle'))
        ->and(trim($badge->textContent))->toBe('Con advertencia');
});

it('escapa el texto y suma las clases que recibe', function () {
    $badge = renderStatusBadge('<x-core::status-badge tone="error" label="<b>Choque</b>" class="shrink-0" />');

    expect(trim($badge->textContent))->toBe('<b>Choque</b>')
        ->and($badge->querySelector('b'))->toBeNull()
        ->and($badge->classList->contains('shrink-0'))->toBeTrue();
});

it('rechaza un tono que no está en DESIGN.md', function () {
    renderStatusBadge('<x-core::status-badge tone="rojo" label="Choque" />');
})->throws(ViewException::class, '"rojo" is not a valid backing value');
