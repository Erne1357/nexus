{{--
    «Oferta» durante la orden de selección (tarea 1.33; maqueta A con el orden en un drawer, como la B): el encabezado
    con la insignia de la selección y «SC Sistemas y Computación · Presencial · Ene-Jun 2027 · 50 grupos, 21 libres»; la
    barra del turno pegada arriba; la barra de filtros («Materia o clave» y «Todos · Libres · Míos») con la leyenda de
    los tipos de espacio; el horario por hora; el drawer del orden, el modal «Terminar mi carga» y el drawer del grupo.
    Se refresca cada 5 s mientras la selección está viva.
--}}
@php
    [$tone, $icon, $label] = match ($selection->status) {
        \App\Modules\Staffing\Enums\SelectionStatus::Published => ['neutral', 'o-calendar', 'Selección publicada'],
        \App\Modules\Staffing\Enums\SelectionStatus::ExtraRound => ['info', 'o-arrow-path', 'Ronda extra'],
        default => ['info', 'o-clock', 'Selección en curso'],
    };
    $inQueue = $teacher !== null;
    $pickOptions = $inQueue ? ['todos' => 'Todos', 'libres' => 'Libres', 'mios' => 'Míos'] : ['todos' => 'Todos', 'libres' => 'Libres'];
@endphp
<div class="space-y-4" wire:poll.5s data-testid="selection-offer">
    <div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Oferta del departamento</h1>
            <x-core::status-badge :tone="$tone" :icon="$icon" :label="$label" />
        </div>
        <p class="mt-1.5 flex flex-wrap gap-x-2 gap-y-0.5 text-neutral" data-testid="selection-meta">
            <span><b class="font-mono text-base-content">{{ $selection->department?->code }}</b> {{ $selection->department?->name }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ $selection->modality?->name }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ $selection->term?->name }}</span>
            <span aria-hidden="true">·</span>
            <span><span class="font-mono text-base-content">{{ $data['groups'] }}</span> {{ $data['groups'] === 1 ? 'grupo' : 'grupos' }}, <span class="font-mono text-base-content">{{ $data['free'] }}</span> {{ $data['free'] === 1 ? 'libre' : 'libres' }}</span>
        </p>
    </div>

    @include('staffing::offer.selection.turn-bar')

    <div class="flex flex-wrap items-center gap-3" role="search" aria-label="Filtrar el horario">
        <div class="w-full md:w-64">
            <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Materia o clave" aria-label="Buscar por materia o clave" clearable data-testid="selection-search" />
        </div>
        <div class="min-w-0 max-md:w-full">
            @include('staffing::shared.segmented-filter', [
                'label' => 'Grupos',
                'property' => 'picks',
                'options' => $pickOptions,
                'current' => $picks,
                'counts' => $pickCounts,
                'testid' => 'selection-picks',
            ])
        </div>
        <p class="flex flex-wrap gap-x-3.5 gap-y-1 text-[12.5px] text-neutral md:ms-auto" aria-label="Tipos de espacio">
            <span class="inline-flex items-center gap-1"><x-icon name="o-book-open" class="h-3.5 w-3.5" aria-hidden="true" />Aula</span>
            <span class="inline-flex items-center gap-1"><x-icon name="o-computer-desktop" class="h-3.5 w-3.5" aria-hidden="true" />Sala de cómputo</span>
            <span class="inline-flex items-center gap-1"><x-icon name="o-beaker" class="h-3.5 w-3.5" aria-hidden="true" />Laboratorio</span>
        </p>
    </div>

    <div class="sr-only" role="status">{{ $shown }} {{ $shown === 1 ? 'grupo' : 'grupos' }}</div>

    @include('staffing::offer.selection.timetable', ['strips' => $strips, 'data' => $data, 'inQueue' => $inQueue])

    @include('staffing::offer.selection.order')

    @if ($inQueue)
        @include('staffing::offer.selection.finish')
    @endif

    <livewire:staffing::components.selection-group-drawer :selection-id="$selection->id" wire:key="selection-drawer-{{ $selection->id }}" />
</div>
