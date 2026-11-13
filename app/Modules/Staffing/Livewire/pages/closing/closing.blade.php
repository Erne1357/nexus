{{--
    Cierre de la carga (tarea 4.1; DESIGN.md, «Cierre de la carga»: maqueta B2, elegida por el usuario el 10 de noviembre
    de 2026; el alcance, maqueta U2, opción A «Selectores en la página», 12 de noviembre). El título con el departamento,
    la modalidad (como dato con una sola) y el periodo; a la derecha, la insignia del estado de la carga y, con más de
    una modalidad, el select «Modalidad» (M1: la carga es por departamento y modalidad; MS16). A quien ve varios, el
    selector «Departamento». De la carga elegida: la línea de estados con la línea del estado, el reparto por campus y sus
    acciones; debajo, a todo el ancho, la publicación con su línea de versiones (staffing::closing.publication); el filtro
    de campus con los grupos de la carga en cada uno, que deja en las justificaciones solo los grupos de ese campus (el
    cierre es de toda la carga); «Para cerrar» en cifras; el historial y las justificaciones
    (staffing::components.closing-justifications, con su drawer aparte). Desde 1280 px el historial es una columna
    lateral; antes, va entre «Para cerrar» y las justificaciones, que son largas.
--}}
<div class="space-y-4">
    @php($single = $modalityOptions->count() <= 1)
    <x-header title="Cierre de la carga" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-0!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="text-sm text-neutral!">
            @if ($closing)
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5" data-testid="closing-meta">
                    <span class="font-mono text-[15px] font-semibold text-base-content">{{ $closing->load->department->code }}</span>
                    <span>{{ $closing->load->department->name }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $single ? $closing->load->modality->name.' · ' : '' }}{{ $closing->load->term->name }}</span>
                </span>
            @else
                Cierra la carga del departamento cuando ya no le falte nada.
            @endif
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap items-end gap-3 max-md:w-full">
                @if ($closing)
                    <x-core::status-badge :tone="$closing->load->status->tone()" :label="\App\Modules\Staffing\Queries\LoadClosing::statusLabel($closing->load)" :icon="\App\Modules\Staffing\Queries\LoadClosing::statusIcon($closing->load)" @class(["mb-2.5" => ! $single]) data-testid="load-status" />
                @endif
                <x-core::modality-scope :options="$modalityOptions" :selected="$modalidad" class="w-full md:w-56" />
            </div>
        </x-slot:actions>
    </x-header>

    @if (count($departments) > 1)
        <div class="w-full md:w-96">
            <x-select label="Departamento" wire:model.live="department" :options="$departments" class="max-md:h-11" data-testid="closing-department" />
        </div>
    @endif


    @if ($closing === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="closing-empty">
            <x-icon name="o-lock-closed" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $message }}</p>
        </div>
    @else
        @php($load = $closing->load)
    @include('staffing::closing.veto')

        <section class="rounded-box border border-base-300 bg-base-100" aria-label="Estado de la carga en {{ $load->modality->name }}" data-testid="load-state">
            <div class="px-4 pt-4 md:px-5">@include('staffing::closing.steps', ['closing' => $closing])</div>
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-base-300 px-4 py-3.5 md:px-5">
                <div class="min-w-0 flex-[1_1_20rem]">
                    @include('staffing::closing.headline', ['closing' => $closing])
                    @if ($campusSplit !== null)
                        <p class="mt-1 ps-[1.875rem] text-sm text-neutral" data-testid="closing-campus-split">{{ $campusSplit }}</p>
                    @endif
                </div>
                <div class="max-md:w-full">@include('staffing::closing.actions', ['actions' => $actions, 'load' => $load, 'prefix' => 'page'])</div>
            </div>
        </section>

        @include('staffing::closing.publication', ['load' => $load, 'visible' => $canViewLoad, 'key' => 'publish'])

        @if ($campusOptions->count() > 1)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="closing-campus">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral" aria-hidden="true">Campus</span>
                <x-core::campus-filter :options="$campusOptions" :selected="$campusFilter?->code" :counts="$campusCounts" all />
                <span class="text-sm text-neutral">Filtra las justificaciones; el cierre es de toda la carga.</span>
            </div>
        @endif

        {{-- Desde 1280 px el historial va a la derecha, pegado arriba; antes, entre «Para cerrar» y «Justificaciones». --}}
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] xl:grid-rows-[auto_1fr]">
            <section class="min-w-0 rounded-box border border-base-300 bg-base-100 xl:col-start-1 xl:row-start-1" aria-labelledby="closing-conditions-title" data-testid="closing-conditions">
                {{-- En celular, el título y la insignia en un renglón y «Revisado a las…» debajo. --}}
                <header class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 md:px-5">
                    <h2 id="closing-conditions-title" class="me-auto text-lg font-bold">Para cerrar</h2>
                    @if ($closing->blockers !== null && $checkedAt)
                        <div class="order-last flex basis-full flex-wrap items-center gap-x-3 md:order-none md:basis-auto">
                            <span class="text-xs text-neutral" data-testid="checked-at">Revisado a las <span class="font-mono tabular-nums">{{ \Carbon\CarbonImmutable::parse($checkedAt)->setTimezone(config('app.timezone'))->format('G:i') }}</span></span>
                            <x-button label="Revisar de nuevo" icon="o-arrow-path" class="btn-ghost btn-xs max-md:h-11" wire:click="recheck" spinner="recheck" data-testid="recheck" />
                        </div>
                    @endif
                    <div class="flex items-center">
                        @if ($closing->blockers === null)
                            <x-core::status-badge tone="neutral" icon="o-minus-circle" label="No aplica" />
                        @elseif ($closing->missing() > 0)
                            <x-core::status-badge :tone="$closing->hasHardConflicts() ? 'error' : 'warning'" :icon="$closing->hasHardConflicts() ? 'o-x-circle' : 'o-exclamation-triangle'" :label="$closing->missingLabel()" data-testid="missing-badge" />
                        @elseif (in_array($load->status, [\App\Modules\AcademicLoad\Enums\LoadStatus::Closed, \App\Modules\AcademicLoad\Enums\LoadStatus::Approved], true))
                            <x-core::status-badge tone="success" label="Sin pendientes" data-testid="missing-badge" />
                        @else
                            <x-core::status-badge tone="success" label="Lista para cerrar" data-testid="missing-badge" />
                        @endif
                    </div>
                </header>
                @if ($closing->blockers === null)
                    <p class="border-t border-base-300 px-4 py-4 text-sm text-neutral md:px-5">
                        {{ $load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Draft ? 'La carga está en borrador: todavía no tiene grupos que cerrar.' : 'La carga ya pasó el cierre. Para cambiarla, Subdirección la reabre.' }}
                    </p>
                @else
                    @if (in_array($load->status, [\App\Modules\AcademicLoad\Enums\LoadStatus::Closed, \App\Modules\AcademicLoad\Enums\LoadStatus::Approved], true) && $closing->missing() > 0)
                        <p class="border-t border-base-300 px-4 py-2.5 text-sm text-warning md:px-5" data-testid="changed-after-close">Algo cambió después del cierre: publicarla se vetará hasta reabrirla y corregirlo.</p>
                    @endif
                    @include('staffing::closing.conditions', ['closing' => $closing])
                @endif
            </section>

            <aside class="rounded-box border border-base-300 bg-base-100 xl:col-start-2 xl:row-span-2 xl:row-start-1 xl:sticky xl:top-[calc(var(--shell-top)+1rem)] xl:flex xl:max-h-[calc(100dvh-var(--shell-top)-2rem)] xl:flex-col" aria-labelledby="closing-history-title" data-testid="closing-history">
                <header class="flex items-baseline justify-between gap-3 border-b border-base-300 px-5 py-3">
                    <h2 id="closing-history-title" class="text-lg font-bold">Historial de la carga</h2>
                    <span class="text-sm text-neutral">{{ count($closing->history) }} {{ count($closing->history) === 1 ? 'cambio' : 'cambios' }}</span>
                </header>
                <div class="xl:overflow-y-auto xl:[scrollbar-width:thin]">@include('staffing::closing.history', ['closing' => $closing])</div>
            </aside>

            <section class="min-w-0 rounded-box border border-base-300 bg-base-100 xl:col-start-1 xl:row-start-2" aria-labelledby="closing-justifications-title" data-testid="closing-justifications">
                <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 pt-3 pb-1 md:px-5">
                    <h2 id="closing-justifications-title" class="text-lg font-bold">Justificaciones</h2>
                    @if ($closing->items !== [])
                        <span class="text-sm text-neutral"><span class="font-mono tabular-nums">{{ $closing->justifiedCount() }}</span> de <span class="font-mono tabular-nums">{{ count($closing->items) }}</span> grupos justificados</span>
                    @endif
                </header>
                {{-- Aparte, para que justificar o abrir un grupo no vuelva a pintar la página; se monta otra vez al cambiar de carga o de estado. --}}
                <livewire:staffing::components.closing-justifications :load-id="$load->id" :with-actions="true" :campus-id="$campusFilter?->id" wire:key="justifications-{{ $load->id }}-{{ $load->status->value }}-{{ $campusFilter?->code ?? 'todos' }}" />
            </section>
        </div>
    @endif

    @include('staffing::closing.modals', ['closing' => $closing])

    @if ($closing)
        <livewire:staffing::components.closing-justify-drawer :load-id="$closing->load->id" wire:key="justify-drawer-{{ $closing->load->id }}" />
    @endif
</div>
