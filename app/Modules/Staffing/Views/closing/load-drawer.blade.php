{{--
    El detalle de una carga en «Avance del cierre» (maqueta E): el departamento; el selector de campus con el estado de
    cada carga (con una sola, no va); la línea de estados; la línea del estado en una franja de su tono; la publicación
    con su línea de versiones, vertical (staffing::closing.publication); lo que vetó la última acción; las pestañas «Para cerrar», «Justificaciones» (solo lectura: se escriben en «Cierre de la carga») e
    «Historial», que cambian en el navegador; y al pie, fijo, «Abrir en Cierre de la carga» y las acciones de la carga.
    `$closing`, `$tabs`, `$actions` y `$canViewLoad`; `close()` es del x-drawer.
--}}
@php($load = $closing->load)
@php($headline = $closing->headline())
@php($missing = $closing->missing())
<div class="flex h-dvh flex-col" data-testid="load-drawer">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="load-drawer-title" class="text-xl font-bold leading-tight"><span class="me-1.5 font-mono">{{ $load->department->code }}</span>{{ $load->department->name }}</h2>
            <p class="mt-1 text-sm text-neutral">
                @if ($tabs->count() > 1)
                    {{ $tabs->sum('active_groups') }} grupos en {{ $tabs->count() }} campus · {{ $load->term->name }}
                @else
                    {{ $closing->activeGroups }} {{ $closing->activeGroups === 1 ? 'grupo' : 'grupos' }} · {{ $load->campus->name }} · {{ $load->term->name }}
                @endif
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" wire:click="closeDrawer" aria-label="Cerrar el detalle" />
    </header>

    <div class="flex-1 space-y-4 overflow-y-auto px-5 pt-4 pb-6">
        @if ($tabs->count() > 1)
            <div>
                <div role="group" aria-label="Campus" class="join flex w-full max-sm:join-vertical" data-testid="drawer-campus">
                    @foreach ($tabs as $tab)
                        @php($active = $tab->id === $load->id)
                        <button type="button" wire:key="drawer-campus-{{ $tab->id }}" wire:click="selectCampus('{{ $tab->campus->code }}')" aria-pressed="{{ $active ? 'true' : 'false' }}"
                            @class(['btn join-item h-auto min-h-11 flex-1 flex-nowrap justify-start gap-2 py-2 font-normal', 'btn-primary' => $active, 'border-base-300 bg-base-100' => ! $active])
                            data-testid="drawer-campus-{{ $tab->campus->code }}">
                            <span class="font-mono font-semibold">{{ $tab->campus->code }}</span>
                            <span class="max-sm:hidden">{{ $tab->campus->name }}</span>
                            <x-core::status-badge :tone="$tab->status->tone()" :label="\App\Modules\Staffing\Queries\LoadClosing::statusLabel($tab)" :icon="\App\Modules\Staffing\Queries\LoadClosing::statusIcon($tab)" class="ms-auto" />
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @include('staffing::closing.steps', ['closing' => $closing])

        <div @class([
            'rounded-box border px-4 py-3',
            'border-error/30 bg-error/10' => $headline['tone'] === 'error',
            'border-warning/30 bg-warning/10' => $headline['tone'] === 'warning',
            'border-success/30 bg-success/10' => $headline['tone'] === 'success',
            'border-info/30 bg-info/10' => $headline['tone'] === 'info',
            'border-base-300 bg-base-200' => $headline['tone'] === 'neutral',
        ])>
            @include('staffing::closing.headline', ['closing' => $closing])
        </div>

        @include('staffing::closing.publication', ['load' => $load, 'visible' => $canViewLoad, 'key' => 'publish-drawer'])

        @include('staffing::closing.veto')

        <div x-data="{ tab: 'para-cerrar' }" wire:key="drawer-tabs-{{ $load->id }}">
            <div class="relative overflow-x-auto overflow-y-hidden [scrollbar-width:none]">
                <div role="tablist" aria-label="Detalle de la carga" class="tabs tabs-border flex-nowrap">
                    <button type="button" role="tab" class="tab flex-nowrap gap-2 whitespace-nowrap max-md:h-11 max-sm:px-2.5" x-bind:class="tab === 'para-cerrar' && 'tab-active font-semibold'" x-bind:aria-selected="tab === 'para-cerrar'" x-on:click="tab = 'para-cerrar'" data-testid="drawer-tab-conditions">
                        Para cerrar
                        @if ($closing->blockers === null)
                        @elseif ($missing > 0)
                            <span @class(['badge badge-sm badge-soft font-mono tabular-nums', 'badge-error' => $closing->hasHardConflicts(), 'badge-warning' => ! $closing->hasHardConflicts()])>{{ $closing->missingGroups() > 0 ? $closing->missingGroups() : $closing->procedures() }}<span class="sr-only">: {{ mb_strtolower($closing->missingLabel()) }}</span></span>
                        @else
                            <x-icon name="o-check-circle" class="h-4 w-4 text-success" aria-hidden="true" /><span class="sr-only">sin faltantes</span>
                        @endif
                    </button>
                    <button type="button" role="tab" class="tab flex-nowrap gap-2 whitespace-nowrap max-md:h-11 max-sm:px-2.5" x-bind:class="tab === 'justificaciones' && 'tab-active font-semibold'" x-bind:aria-selected="tab === 'justificaciones'" x-on:click="tab = 'justificaciones'" data-testid="drawer-tab-justifications">
                        Justificaciones <span @class(['badge badge-sm badge-soft font-mono tabular-nums', 'badge-warning' => $closing->pendingJustifications() > 0, 'badge-neutral' => $closing->pendingJustifications() === 0])>{{ $closing->justifiedCount() }}/{{ count($closing->items) }}</span>
                    </button>
                    <button type="button" role="tab" class="tab flex-nowrap gap-2 whitespace-nowrap max-md:h-11 max-sm:px-2.5" x-bind:class="tab === 'historial' && 'tab-active font-semibold'" x-bind:aria-selected="tab === 'historial'" x-on:click="tab = 'historial'" data-testid="drawer-tab-history">
                        Historial <span class="badge badge-sm badge-soft badge-neutral font-mono tabular-nums">{{ count($closing->history) }}</span>
                    </button>
                </div>
            </div>

            <div x-show="tab === 'para-cerrar'" class="pt-1">
                @if ($closing->blockers === null)
                    <p class="py-4 text-sm text-neutral">{{ $load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Draft ? 'La carga está en borrador: todavía no tiene grupos que cerrar.' : 'La carga ya pasó el cierre. Para cambiarla, reábrela.' }}</p>
                @else
                    @include('staffing::closing.condition-rows', ['closing' => $closing])
                @endif
            </div>
            <div x-show="tab === 'justificaciones'" x-cloak class="-mx-5 pt-3">
                <livewire:staffing::components.closing-justifications :load-id="$load->id" :with-actions="false" wire:key="drawer-justifications-{{ $load->id }}" />
            </div>
            <div x-show="tab === 'historial'" x-cloak class="-mx-5">
                @include('staffing::closing.history', ['closing' => $closing])
            </div>
        </div>
    </div>

    <footer class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 border-t border-base-300 bg-base-100 px-5 py-3">
        <a href="{{ route('staffing.closing', ['departamento' => $load->department_id, 'campus' => $load->campus->code]) }}" wire:navigate class="btn btn-ghost max-md:h-11 max-md:w-full" data-testid="open-closing">
            <x-icon name="o-arrow-right" class="h-5 w-5" aria-hidden="true" />Abrir en Cierre de la carga
        </a>
        <div class="max-md:w-full">@include('staffing::closing.actions', ['actions' => $actions, 'load' => $load, 'prefix' => 'drawer'])</div>
    </footer>
</div>
