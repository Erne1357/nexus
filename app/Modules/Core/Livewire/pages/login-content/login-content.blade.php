{{--
    «Avisos y atajos» (core::pages.login-content): encabezado, pestañas Avisos y Atajos y, en cada una, la anatomía de una
    pantalla de catálogo (DESIGN.md): barra de herramientas común (búsqueda, estado y «Dar de alta»), tabla en una superficie
    con filete y «Editar» visible con lo demás en el menú «Acciones»; debajo de 768 px, renglones apilados. El formulario va
    en un drawer. «Se ve hoy» usa la regla de LG-01 y LG-02 (HasVisibilityWindow::visibilityAt()).
--}}
@php
    $isNotices = $tab === 'notices';
    $noun = $isNotices ? 'aviso' : 'atajo';
    $visibility = [
        'visible' => ['success', 'Sí'],
        'inactive' => ['neutral', 'No: desactivado'],
        'upcoming' => ['info', 'No: aún no empieza'],
        'expired' => ['neutral', 'No: ya terminó'],
    ];
    $span = fn ($item): string => match (true) {
        $item->starts_at === null && $item->ends_at === null => 'Sin fechas',
        $item->starts_at === null => 'Hasta el '.$item->ends_at->format('d/m/Y H:i'),
        $item->ends_at === null => 'Desde el '.$item->starts_at->format('d/m/Y H:i'),
        default => $item->starts_at->format('d/m/Y H:i').' al '.$item->ends_at->format('d/m/Y H:i'),
    };
@endphp
<div>
    <x-header title="Avisos y atajos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Lo que se ve en la pantalla de acceso: los avisos del carrusel y el botón de atajos sobre la foto.
        </x-slot:subtitle>
    </x-header>

    <div role="tablist" aria-label="Avisos y atajos" class="tabs tabs-border mb-4 flex-nowrap overflow-x-auto">
        @foreach ($tabs as $item)
            <button type="button" role="tab" id="tab-{{ $item['key'] }}" aria-selected="{{ $tab === $item['key'] ? 'true' : 'false' }}" data-testid="tab-{{ $item['key'] }}"
                wire:click="$set('tab', '{{ $item['key'] }}')" @class(['tab shrink-0', 'tab-active font-bold' => $tab === $item['key']])>
                {{ $item['label'] }}
            </button>
        @endforeach
    </div>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" placeholder="Buscar por título" :aria-label="$isNotices ? 'Buscar avisos' : 'Buscar atajos'" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Activos e inactivos" />

        <x-slot:action>
            @if ($canManage)
                <x-button label="Dar de alta" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" data-testid="create" />
            @else
                <span class="tooltip tooltip-left" data-tip="No tienes permiso para dar de alta {{ $isNotices ? 'avisos' : 'atajos' }}.">
                    <x-button label="Dar de alta" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" wire:key="list-{{ $tab }}">
        @if ($items->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-neutral" data-testid="empty">
                {{ $total === 0 ? ($isNotices ? 'Todavía no hay avisos.' : 'Todavía no hay atajos.') : 'Nada coincide con la búsqueda.' }}
            </p>
        @else
            <table class="table max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40">
                <thead>
                    <tr>
                        <th class="w-12">Orden</th>
                        <th>Título</th>
                        @unless ($isNotices)
                            <th>Tipo</th>
                        @endunless
                        <th>Vigencia</th>
                        <th>Se ve hoy</th>
                        <th>Estado</th>
                        <th class="w-px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $row)
                        @php([$tone, $why] = $visibility[$row->visibilityAt($now)])
                        <tr wire:key="row-{{ $tab }}-{{ $row->id }}" @class(['text-neutral' => ! $row->is_active])>
                            <td class="font-mono tabular-nums">{{ $loop->iteration }}</td>
                            <td @class(['min-w-36 font-semibold' => $row->is_active])>{{ $row->title }}</td>
                            @unless ($isNotices)
                                <td>{{ $row->kind === \App\Modules\Core\Enums\LoginShortcutKind::Download ? 'Descarga' : 'Enlace' }}</td>
                            @endunless
                            <td class="whitespace-nowrap text-sm">{{ $span($row) }}</td>
                            <td><x-core::status-badge :tone="$tone" :label="$why" /></td>
                            <td><x-core::status-badge :tone="$row->is_active ? 'success' : 'neutral'" :label="$row->is_active ? 'Activo' : 'Inactivo'" /></td>
                            <td>@include('core::login-content.row-actions', ['row' => $row, 'inTable' => true])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="login-content-cards">
                @foreach ($items as $row)
                    @php([$tone, $why] = $visibility[$row->visibilityAt($now)])
                    <li wire:key="card-{{ $tab }}-{{ $row->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $row->is_active])>
                        <div class="flex items-center gap-2.5">
                            <span class="font-mono text-[15px] tabular-nums">{{ $loop->iteration }}</span>
                            <span @class(['min-w-0 flex-1', 'font-semibold' => $row->is_active])>{{ $row->title }}</span>
                            <x-core::status-badge :tone="$row->is_active ? 'success' : 'neutral'" :label="$row->is_active ? 'Activo' : 'Inactivo'" />
                        </div>
                        <p class="text-sm">{{ $span($row) }}</p>
                        <p class="text-sm">Se ve hoy: <x-core::status-badge :tone="$tone" :label="$why" /></p>
                        @include('core::login-content.row-actions', ['row' => $row, 'inTable' => false])
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="login-content-drawer-title" data-testid="login-content-drawer">
        @if ($showDrawer)
            @include('core::login-content.form')
        @endif
    </x-drawer>
</div>
