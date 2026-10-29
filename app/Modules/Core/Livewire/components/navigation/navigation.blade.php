<nav aria-label="Navegación principal">
    @foreach ($groups as $group)
        {{-- La lista lleva el nombre del área; el título visible no se repite al lector de pantalla. --}}
        <x-menu :aria-label="$group['area']->label()" activate-by-route>
            <x-menu-title :title="$group['area']->label()" :icon="$group['area']->icon()" aria-hidden="true" />

            @foreach ($group['items'] as $item)
                <x-menu-item :title="$item->label" :icon="$item->icon" :route="$item->route" />
            @endforeach
        </x-menu>
    @endforeach
</nav>
