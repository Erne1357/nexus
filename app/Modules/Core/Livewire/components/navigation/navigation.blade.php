<nav aria-label="Navegación principal">
    <x-menu activate-by-route>
        @foreach ($items as $item)
            <x-menu-item :title="$item->label" :icon="$item->icon" :route="$item->route" />
        @endforeach
    </x-menu>
</nav>
