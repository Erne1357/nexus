{{--
    Pestaña «Puestos» (core::pages.permissions; maqueta B): a la izquierda, desde 1200 px, las tarjetas de los siete
    puestos en una columna de 300 px pegada arriba; antes, una tira que se desplaza de lado sobre el detalle. Cada
    tarjeta dice sus personas, roles, sueltos y permisos, y «N nuevos de fábrica» si su rol de fábrica los tiene (CF-02).
    A la derecha, el puesto elegido (`?puesto=`): su nombre, a quién cubre y cuántas personas lo ocupan, «Agregar rol»,
    «Agregar permiso suelto» y «Más acciones» (Restablecer puesto); el aviso de lo nuevo de fábrica con «Aplicar»; sus
    roles, sus permisos sueltos y sus permisos efectivos por grupo, con buscador, «Todos / Fuera de fábrica» y una
    insignia de origen. Administración es de solo lectura: todo menos sus excepciones, que se listan (CF-S1). Espera
    `$cards`, `$current` y `$canManage`.
--}}
<div class="grid grid-cols-[minmax(0,1fr)] items-start gap-5 desktop:grid-cols-[300px_minmax(0,1fr)]">
    <nav
        aria-label="Puestos"
        class="flex gap-2 overflow-x-auto pb-1 desktop:sticky desktop:top-[calc(var(--shell-top)+1rem)] desktop:grid desktop:overflow-visible desktop:pb-0"
        x-data
        x-init="const card = $el.querySelector('[aria-current]'); if (card && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft = card.offsetLeft - $el.offsetLeft - 16 }"
    >
        @foreach ($cards as $card)
            <button
                type="button"
                wire:key="position-card-{{ $card['value'] }}"
                wire:click="$set('position', '{{ $card['value'] }}')"
                data-testid="position-card"
                data-position="{{ $card['value'] }}"
                @if ($card['selected']) aria-current="true" @endif
                @class([
                    'relative grid w-52 shrink-0 grid-cols-[minmax(0,1fr)_auto] content-start gap-x-2.5 gap-y-0.5 rounded-box border px-3.5 py-3 text-start max-md:w-44 desktop:w-full',
                    'border-base-300 bg-base-100 hover:border-base-content/25' => ! $card['selected'],
                    'border-secondary bg-secondary shadow-[inset_3px_0_0_var(--color-primary)]' => $card['selected'],
                ])
            >
                <span class="min-w-0 font-bold">{{ $card['label'] }}</span>
                <span class="inline-flex items-center gap-1 self-start font-mono text-[13px] font-semibold text-neutral">
                    <x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />{{ number_format($card['holders']) }}<span class="sr-only"> {{ $card['holders'] === 1 ? 'persona' : 'personas' }}</span>
                </span>
                <span @class(['col-span-2 text-[13px]', 'text-neutral' => ! $card['selected'], 'text-secondary-muted' => $card['selected']])>{{ $card['summary'] }}</span>
                @if ($card['new'] > 0)
                    <span class="col-span-2 mt-1 justify-self-start">
                        <x-core::status-badge tone="warning" icon="o-sparkles" :label="$card['new'].($card['new'] === 1 ? ' nuevo de fábrica' : ' nuevos de fábrica')" />
                    </span>
                @endif
            </button>
        @endforeach
    </nav>

    <section class="min-w-0 space-y-5 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="position-title" data-testid="position-detail" wire:key="position-detail-{{ $current['value'] }}">
        <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 id="position-title" class="text-xl font-bold leading-tight">{{ $current['label'] }}</h2>
                    @if ($current['locked'])
                        <x-core::status-badge tone="neutral" icon="o-lock-closed" label="No se edita" />
                    @endif
                </div>
                <p class="mt-1 text-sm text-neutral">{{ number_format($current['holders']) }} {{ $current['holders'] === 1 ? 'persona' : 'personas' }} · {{ $current['scope'] }}</p>
            </div>

            @unless ($current['locked'])
                <div class="flex flex-wrap gap-2 max-md:w-full">
                    <x-button label="Agregar rol" icon="o-plus" class="btn-outline max-md:h-11 max-md:basis-full" wire:click="openAddRole" spinner="openAddRole" :disabled="! $canManage" data-testid="add-role" />
                    <x-button label="Agregar permiso suelto" icon="o-plus" class="btn-outline max-md:h-11 max-md:flex-1" wire:click="openAddLoose" spinner="openAddLoose" :disabled="! $canManage" data-testid="add-loose" />
                    <x-core::row-menu icon="o-ellipsis-vertical" label="Más acciones">
                        <x-core::row-menu-item
                            label="Restablecer puesto"
                            wire:click="restorePosition"
                            x-confirm="Se queda solo con su rol de fábrica y sin permisos sueltos."
                            data-confirm-title="¿Restablecer {{ $current['label'] }}?"
                            data-confirm-button="Restablecer puesto"
                            data-confirm-tone="warning"
                            spinner="restorePosition"
                            data-testid="restore-position"
                        />
                    </x-core::row-menu>
                </div>
            @endunless
        </div>

        @if ($current['locked'])
            <div class="flex items-start gap-3 rounded-box border border-base-300 bg-secondary/40 px-4 py-3">
                <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Tiene todo lo declarado menos sus excepciones</p>
                    <p class="text-sm text-neutral">{{ number_format($current['effectiveCount']) }} de {{ number_format($current['declared']) }} permisos. Las excepciones siguen en código.</p>
                </div>
            </div>

            <section class="space-y-2" aria-labelledby="denies-title">
                <h3 id="denies-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Excepciones · {{ count($current['denies']) }}</h3>
                <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300">
                    @foreach ($current['denies'] as $deny)
                        <li wire:key="deny-{{ $deny['key'] }}" class="grid gap-px px-3.5 py-2.5">
                            <span class="font-semibold">{{ $deny['label'] }}</span>
                            <span class="break-all font-mono text-[12.5px] text-neutral">{{ $deny['key'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            @if ($current['newFactory'] !== null)
                @php($newCount = count($current['newFactory']['labels']))
                <div class="flex flex-wrap items-start gap-3 rounded-box border border-warning/40 bg-warning/10 px-4 py-3" role="status" data-testid="new-factory">
                    <x-icon name="o-sparkles" class="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ $newCount }} {{ $newCount === 1 ? 'permiso nuevo de fábrica' : 'permisos nuevos de fábrica' }}</p>
                        <p class="text-sm">{{ implode(' · ', $current['newFactory']['labels']) }}</p>
                    </div>
                    <x-button label="Aplicar" class="btn-sm btn-outline bg-base-100 max-md:h-11" wire:click="applyFactoryUpdates('{{ $current['newFactory']['id'] }}')" spinner="applyFactoryUpdates('{{ $current['newFactory']['id'] }}')" :disabled="! $canManage" data-testid="apply-new" />
                </div>
            @endif

            <section class="space-y-2" aria-labelledby="sets-title">
                <h3 id="sets-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Roles · {{ count($current['sets']) }}</h3>
                @if ($current['sets'] === [])
                    <p class="text-sm text-neutral">Sin roles.</p>
                @else
                    <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="position-sets">
                        @foreach ($current['sets'] as $set)
                            <li wire:key="set-{{ $set['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                                <x-icon name="o-rectangle-stack" class="h-4.5 w-4.5 shrink-0 text-neutral" aria-hidden="true" />
                                <div class="grid min-w-0 flex-1 gap-px">
                                    <span @class(['font-semibold', 'text-neutral line-through' => ! $set['is_active']])>{{ $set['name'] }}</span>
                                    <span class="text-[12.5px] text-neutral">{{ $set['count'] }} {{ $set['count'] === 1 ? 'permiso' : 'permisos' }}@if ($set['others'] !== []) · también {{ implode(', ', $set['others']) }}@endif</span>
                                </div>
                                @if ($set['badge'] !== null)
                                    <x-core::status-badge :tone="$set['badge']['tone']" :label="$set['badge']['label']" :icon="$set['badge']['icon']" />
                                @endif
                                <div class="ms-auto flex gap-1">
                                    <x-button label="Editar" class="btn-ghost btn-sm max-md:h-11" wire:click="editRole('{{ $set['id'] }}')" spinner="editRole('{{ $set['id'] }}')" :disabled="! $canManage" aria-label="Editar {{ $set['name'] }}" />
                                    <x-button
                                        label="Quitar"
                                        class="btn-ghost btn-sm max-md:h-11"
                                        wire:click="unassignRole('{{ $set['id'] }}')"
                                        x-confirm="{{ $current['label'] }} deja de recibir lo que trae este rol, salvo lo que le llegue por otro lado."
                                        data-confirm-title="¿Quitar «{{ $set['name'] }}» a {{ $current['label'] }}?"
                                        data-confirm-button="Quitar rol"
                                        data-confirm-tone="error"
                                        spinner="unassignRole('{{ $set['id'] }}')"
                                        :disabled="! $canManage"
                                        aria-label="Quitar {{ $set['name'] }}"
                                    />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="space-y-2" aria-labelledby="loose-title">
                <h3 id="loose-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Permisos sueltos · {{ count($current['loose']) }}</h3>
                @if ($current['loose'] === [])
                    <p class="text-sm text-neutral">Sin permisos sueltos.</p>
                @else
                    <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="position-loose">
                        @foreach ($current['loose'] as $loose)
                            <li wire:key="loose-{{ $loose['key'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                                <x-icon name="o-plus-circle" class="h-4.5 w-4.5 shrink-0 text-neutral" aria-hidden="true" />
                                <div class="grid min-w-0 flex-1 gap-px">
                                    <span class="font-semibold">{{ $loose['label'] }}</span>
                                    <span class="break-all font-mono text-[12.5px] text-neutral">{{ $loose['key'] }}</span>
                                </div>
                                <x-button
                                    label="Quitar"
                                    class="btn-ghost btn-sm ms-auto max-md:h-11"
                                    wire:click="revokeLoose('{{ $loose['key'] }}')"
                                    x-confirm="{{ $current['label'] }} lo pierde, salvo que le llegue por uno de sus roles."
                                    data-confirm-title="¿Quitar «{{ $loose['label'] }}»?"
                                    data-confirm-button="Quitar permiso"
                                    data-confirm-tone="error"
                                    spinner="revokeLoose('{{ $loose['key'] }}')"
                                    :disabled="! $canManage"
                                    aria-label="Quitar {{ $loose['label'] }}"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="space-y-2.5" aria-labelledby="effective-title">
            <h3 id="effective-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Permisos efectivos · {{ $current['effectiveCount'] }}</h3>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <div class="w-full md:w-72">
                    <x-input wire:model.live.debounce.300ms="effectiveSearch" placeholder="Busca en sus permisos" aria-label="Buscar en sus permisos" icon="o-magnifying-glass" clearable />
                </div>
                @unless ($current['locked'])
                    <div class="join max-md:grid max-md:w-full max-md:grid-cols-2" role="group" aria-label="Mostrar">
                        <button type="button" wire:click="$set('outsideFactory', false)" aria-pressed="{{ $outsideFactory ? 'false' : 'true' }}" @class(['btn join-item max-md:min-h-11', 'btn-primary' => ! $outsideFactory])>Todos</button>
                        <button type="button" wire:click="$set('outsideFactory', true)" aria-pressed="{{ $outsideFactory ? 'true' : 'false' }}" @class(['btn join-item max-md:min-h-11', 'btn-primary' => $outsideFactory])>Fuera de fábrica</button>
                    </div>
                @endunless
            </div>

            @if ($current['groups'] === [])
                <p class="rounded-box border border-base-300 px-4 py-3 text-sm text-neutral">{{ $current['filtering'] ? 'Ningún permiso coincide.' : 'Este puesto no tiene permisos hoy.' }}</p>
            @else
                <div class="overflow-hidden rounded-box border border-base-300" data-testid="effective-list">
                    @foreach ($current['groups'] as $group)
                        <div wire:key="effective-{{ $current['listKey'] }}-{{ $group['id'] }}" x-data="{ open: @js($group['open']) }" class="border-t border-base-300 first:border-t-0">
                            <button type="button" class="flex min-h-11 w-full items-center gap-2.5 bg-secondary/40 px-3.5 text-start font-semibold" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'">
                                <x-icon name="o-chevron-right" class="h-4 w-4 shrink-0 text-neutral transition-transform motion-reduce:transition-none" x-bind:class="open && 'rotate-90'" aria-hidden="true" />
                                <span class="min-w-0 flex-1">{{ $group['label'] }}</span>
                                <span class="font-mono text-[12.5px] text-neutral">{{ count($group['rows']) }}</span>
                            </button>
                            <ul x-show="open" @unless ($group['open']) x-cloak @endunless>
                                @foreach ($group['rows'] as $permission)
                                    <li wire:key="effective-row-{{ $permission['key'] }}" data-testid="effective-row-{{ $permission['key'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-t border-base-300 py-2 pe-3.5 ps-10 max-md:grid-cols-1 max-md:ps-3.5">
                                        <span class="grid min-w-0 gap-px">
                                            <span>{{ $permission['label'] }}</span>
                                            <span class="break-all font-mono text-[12.5px] text-neutral">{{ $permission['key'] }}</span>
                                        </span>
                                        <span class="max-md:[&>span]:justify-start">@include('core::permissions.chips', ['chips' => $permission['chips']])</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </section>
</div>
