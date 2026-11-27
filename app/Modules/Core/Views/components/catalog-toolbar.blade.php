{{--
    Barra de herramientas de una pantalla de catálogo (DESIGN.md, "Anatomía de una pantalla de
    catálogo"), la misma en todas: en su propio renglón debajo del título, nunca en el slot `actions`
    de x-header. A la izquierda, `search` (md:w-64) y luego los filtros del slot por omisión, cada uno
    de md:w-52, los de catálogo primero y el de estado al final; a la derecha, siempre en el mismo
    lugar, la acción principal en `action`. Debajo de 768 px cada control ocupa todo el ancho.

    `narrow`, en los catálogos con cuatro filtros o con «Más acciones» junto a la acción principal: la búsqueda
    de md:w-56 y los filtros de md:w-40, para que todo quepa en un renglón desde 1440 px. Entonces la opción
    «todos» de cada filtro dice solo el nombre del filtro («Departamento», «Estado») y la búsqueda solo qué se
    busca («Clave o nombre»), para que ningún texto se corte (DESIGN.md).

    <x-core::catalog-toolbar>
        <x-slot:search><x-input ... /></x-slot:search>
        <x-select ... />
        <x-slot:action><x-button class="btn-primary" ... /></x-slot:action>
    </x-core::catalog-toolbar>
--}}
@props(['search', 'action' => null, 'narrow' => false])

<div {{ $attributes->class(['mb-4 flex flex-wrap items-start gap-x-2.5 gap-y-3', 'md:items-center']) }} data-testid="catalog-toolbar">
    <div @class(['w-full', 'md:w-56' => $narrow, 'md:w-64' => ! $narrow]) data-testid="catalog-search">
        {{ $search }}
    </div>

    <div @class(['contents [&>*]:w-full', 'md:[&>*]:w-40' => $narrow, 'md:[&>*]:w-52' => ! $narrow]) data-testid="catalog-filters">
        {{ $slot }}
    </div>

    @if ($action !== null && $action->isNotEmpty())
        <div class="w-full md:ms-auto md:w-auto [&_.btn]:max-md:w-full">
            {{ $action }}
        </div>
    @endif
</div>
