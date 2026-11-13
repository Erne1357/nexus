{{--
    Encabezado de la asignación docente: título, departamento · campus · periodo y, debajo, el selector de departamento
    (a quien ve varios: Subdirección, admin); a la derecha, la tarjeta «Carga del departamento» con su estado y cuántos
    grupos tienen docente.
--}}
@php($total = $board->total())
@php($assigned = $board->assignedCount())
<div class="flex flex-wrap items-end justify-between gap-x-7 gap-y-4">
    <div class="min-w-0 max-md:w-full">
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Asignación docente</h1>
        <p class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-neutral" data-testid="assign-meta">
            <span class="font-medium text-base-content">{{ $board->department->name }}</span>
            <span>{{ $board->campus?->name }}</span>
            <span>{{ $board->term->name }}</span>
        </p>
        <x-core::campus-filter :options="$campusOptions" :selected="$campusCode" class="mt-3" />
        @if (count($departments) > 1)
            <div class="mt-3 w-full md:w-80">
                <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm max-md:h-11" data-testid="assign-department" />
            </div>
        @endif
    </div>

    <div class="flex flex-wrap items-end gap-3 max-md:w-full">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5 max-md:w-full" data-testid="load-card">
            <div class="grid gap-1">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carga del departamento</span>
                @if ($board->load === null)
                    <x-core::status-badge tone="draft" label="Sin carga" data-testid="load-status" />
                @else
                    <x-core::status-badge :tone="$board->load->status->tone()" :label="$board->load->status->label()" data-testid="load-status" />
                @endif
            </div>
            <div class="grid min-w-48 gap-1.5 max-md:flex-1" data-testid="load-progress">
                <span class="flex items-baseline gap-1.5"><span class="font-mono text-xl font-semibold leading-none tabular-nums">{{ $assigned }}</span> <span class="text-sm text-neutral">de <span class="font-mono tabular-nums">{{ $total }}</span> con docente</span></span>
                <progress class="progress progress-primary h-1.5 w-full" value="{{ $assigned }}" max="{{ max($total, 1) }}" aria-label="Grupos con docente: {{ $assigned }} de {{ $total }}"></progress>
            </div>
        </div>
    </div>
</div>
