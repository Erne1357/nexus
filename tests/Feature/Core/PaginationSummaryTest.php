<?php

use Illuminate\Pagination\LengthAwarePaginator;

/*
| El paginador compartido (resources/views/vendor/livewire/tailwind.blade.php) trae por omisión el resumen
| «Mostrando x al y de z resultados» y un catálogo con su propio conteo lo puede quitar con `summary => false`.
*/

function paginatorHtml(array $data = []): string
{
    $paginator = new LengthAwarePaginator(range(1, 15), 42, 15, 1, ['path' => '/x']);

    return (string) $paginator->links('livewire::tailwind', $data);
}

it('pinta el resumen en español por omisión', function () {
    $html = paginatorHtml();

    expect($html)->toContain('Mostrando')->toContain('resultados')->toContain('wire:click="gotoPage(2');
});

it('no pinta el resumen cuando el catálogo lo quita, pero conserva los controles', function () {
    $html = paginatorHtml(['summary' => false]);

    expect($html)->not->toContain('Mostrando')->toContain('wire:click="gotoPage(2');
});
