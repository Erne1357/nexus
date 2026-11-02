{{--
    El drawer de bajas de la lista: los registros de la foto de la validación anterior que ya no están activos (se
    desactivaron, se borraron o dejaron el departamento), con la clave y el nombre de la foto. `$removed` es el
    `removed` de CatalogReviewQuery::summary().
--}}
<div class="flex min-h-dvh flex-col" data-testid="removed-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="removed-drawer-title" class="text-xl font-bold leading-tight">Bajas de {{ $review->catalog->label() }}</h2>
            <p class="mt-1 text-sm text-neutral"><span class="font-mono">{{ count($removed) }}</span> {{ count($removed) === 1 ? 'registro de la validación anterior ya no está activo' : 'registros de la validación anterior ya no están activos' }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <ul class="divide-y divide-base-300 px-5 pb-8">
        @foreach ($removed as $record)
            <li wire:key="removed-{{ $record['id'] }}" class="flex items-baseline gap-3 py-2.5 text-sm">
                <span class="w-28 shrink-0 font-mono text-[15px] font-semibold tabular-nums">{{ $record['code'] }}</span>
                <span class="min-w-0 flex-1">{{ $record['name'] ?? '—' }}</span>
            </li>
        @endforeach
    </ul>
</div>
