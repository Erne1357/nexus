{{--
    La pestaña «Paralelos» de la bandeja del departamento (tarea 8.8, maqueta B; DESIGN.md, «Paralelos»). El mismo
    encabezado que la bandeja (departamento, modalidad, periodo y el estado de la carga), el filtro de campus con las
    materias por juntar de cada uno y las pestañas «Bandeja · Paralelos». Luego las cuatro cifras, la búsqueda y «Todas ·
    Por armar · Armadas», la tabla de materias por campus (renglones apilados debajo de 768 px) y lo que no hay que
    juntar, plegado con su motivo. El drawer de una materia arma sus paralelos. Todo lo que se pinta viene de
    ParallelsQuery y ParallelDraft.
--}}
@php
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
@endphp
<div class="space-y-5"
    x-on:click.capture="const trigger = $event.target.closest('[data-opens]'); if (trigger) { opener = trigger.dataset.opens }"
    x-data="{
        opener: null,
        init() {
            this.$wire.$watch('showDrawer', (open) => {
                if (open || this.opener === null) { return }
                setTimeout(() => this.$root.querySelector(`[data-opens='${this.opener}']`)?.focus({ preventScroll: true }), 80)
            })
        },
    }">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Bandeja del departamento</h1>
            @if ($parallels !== null)
                <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                    <span class="font-medium text-base-content" data-testid="parallels-department">{{ $parallels->department->name }}</span>
                    @if ($modalityOptions->count() <= 1)
                        <span>{{ $parallels->modality->name }}</span>
                    @endif
                    <span>{{ $parallels->term->name }}</span>
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-end gap-3 max-md:w-full">
            <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" class="max-md:w-full md:w-56" />
            @if (count($departments) > 1)
                <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm max-md:h-11" data-testid="parallels-department-select" />
            @endif
            @if ($parallels !== null)
                <div class="flex flex-col gap-1.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5 max-md:w-full">
                    <span class="{{ $label }}">Carga del departamento</span>
                    @if ($parallels->loadStatus === null)
                        <x-core::status-badge tone="draft" label="Sin carga" data-testid="parallels-load-status" />
                    @else
                        <x-core::status-badge :tone="$parallels->loadStatus->tone()" :label="$parallels->loadStatus->label()" data-testid="parallels-load-status" />
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($parallels === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="parallels-empty">{{ $message }}</div>
    @else
        @if ($campusOptions->count() > 1)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="parallels-campus">
                <span class="{{ $label }} max-md:hidden" aria-hidden="true">Campus</span>
                <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" :counts="$campusCounts" :total="$campusTotal" all class="max-md:w-full" />
                <span class="text-sm text-neutral">Filtra las materias y las cifras. Un grupo es de un campus: los paralelos no juntan campus.</span>
            </div>
        @endif

        @include('academic-load::inbox.tabs', ['active' => 'parallels', 'parallelsCount' => $pendingCount])

        @if (! $canArrange)
            <x-alert icon="o-eye" class="alert-info alert-soft text-sm" data-testid="parallels-read-only">
                <span><b class="font-semibold">Solo consulta.</b> Arma los grupos la jefatura de {{ $parallels->department->name }}, que imparte las materias; tú ves lo que se arma.</span>
            </x-alert>
        @endif

        @if ($parallels->blockedReason !== null)
            <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" data-testid="parallels-blocked-load">
                <span>{{ $parallels->blockedReason }}</span>
            </x-alert>
        @endif

        @include('scheduling::parallels.figures')

        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-3" data-testid="parallels-toolbar">
            <div class="w-full md:w-64">
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Clave o materia" aria-label="Buscar por clave o materia" clearable class="max-md:h-11" data-testid="parallels-search" />
            </div>
            @php
                $filterOptions = array_filter(['' => 'Todas', 'por-armar' => 'Por armar', 'pendientes' => 'Pendientes', 'armadas' => 'Armadas'], fn ($optionLabel, $option) => $option !== 'pendientes' || $filterCounts['pendientes'] > 0 || $filter === 'pendientes', ARRAY_FILTER_USE_BOTH);
            @endphp
            <div role="group" aria-label="Estado" class="join grid w-full md:flex md:w-auto" style="grid-template-columns: repeat({{ count($filterOptions) }}, minmax(0, 1fr))" data-testid="parallels-filter">
                @foreach ($filterOptions as $option => $optionLabel)
                    <button type="button" wire:click="$set('filter', '{{ $option }}')" aria-pressed="{{ $filter === $option ? 'true' : 'false' }}" data-testid="parallels-filter-{{ $option ?: 'todas' }}"
                        @class([
                            'btn join-item px-2 max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:flex-col max-md:gap-0 max-md:py-1 max-md:leading-tight md:gap-1.5 md:whitespace-nowrap md:px-3',
                            'btn-primary' => $filter === $option,
                        ])><span class="max-md:max-w-full max-md:truncate">{{ $optionLabel }}</span> <span class="font-mono text-[12.5px] tabular-nums opacity-85">{{ $filterCounts[$option] }}</span></button>
                @endforeach
            </div>
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-neutral desktop:ms-auto" data-testid="parallels-legend">
                <span class="inline-flex items-center gap-1.5">@include('scheduling::parallels.career-chip', ['code' => 'ISIC', 'name' => 'Ejemplo de carrera con su parte aceptada', 'accepted' => true])<span>parte aceptada: sus grupos ya existen y se reorganizan</span></span>
                <span>Cuentan las partes aceptadas y las pendientes</span>
            </p>
        </div>

        @include('scheduling::parallels.list')

        @if ($nothing !== [])
            <details class="group rounded-box border border-base-300 bg-base-100" @if ($figures['subjects'] === 0) open @endif data-testid="parallels-nothing">
                <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold [&::-webkit-details-marker]:hidden">
                    <x-icon name="o-chevron-down" class="h-4 w-4 transition-transform group-open:rotate-180" aria-hidden="true" />
                    Nada que juntar <span class="font-normal text-neutral">· {{ count($nothing) }} {{ count($nothing) === 1 ? 'materia y campus' : 'materias y campus' }}</span>
                </summary>
                <ul class="grid gap-x-8 gap-y-1.5 border-t border-base-300 px-4 py-3 text-sm lg:grid-cols-2">
                    @foreach ($nothing as $subject)
                        <li class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5" wire:key="nothing-{{ $subject->subject->id }}-{{ $subject->campus->id }}">
                            <x-core::campus-tag :campus="$subject->campus" />
                            <span class="font-mono font-semibold">{{ $subject->subject->code }}</span>
                            <span class="min-w-0">{{ $subject->subject->name }}</span>
                            <span class="text-neutral">· {{ $subject->whyNot }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-6xl p-0!" role="dialog" aria-modal="true" aria-labelledby="parallels-drawer-title" data-testid="parallels-drawer">
            @if ($draft !== null)
                @include('scheduling::parallels.drawer')
            @elseif ($showDrawer)
                {{-- La materia salió de la pestaña entre una petición y otra (otra parte se regresó): se dice, sin cerrar solo. --}}
                <div class="flex h-dvh flex-col" data-testid="parallels-panel-gone">
                    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                        <h2 id="parallels-drawer-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">Materia sin paralelos</h2>
                        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                    </header>
                    <p class="px-5 py-6 text-neutral">Esta materia ya no está en la pestaña: cambió lo que piden las carreras.</p>
                </div>
            @endif
        </x-drawer>
    @endif
</div>
