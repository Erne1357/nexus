{{--
    Solicitudes de cambio (tarea 3.11, maqueta A «Lista y detalle»; el alcance, maqueta U2, opción A): encabezado con el
    departamento y, a la derecha, su selector (si hay varios, del tamaño y en el lugar del de la asignación), el select
    «Modalidad» (con más de una) y «Nueva solicitud» para el coordinador; el filtro de campus con las solicitudes de cada
    uno; el aviso con el estado de la carga del departamento, los filtros (estado con sus cifras, tipo y texto) y los dos
    paneles: la lista a la izquierda y el detalle fijo a la derecha. Desde lg van juntos; debajo, la lista y el detalle
    van por separado (el detalle ocupa la pantalla, con «Solicitudes» para regresar). Todo lo que se pinta viene de
    ChangeRequestsQuery.
--}}
<div class="space-y-4" x-on:request-detail-opened.window="$nextTick(() => {
        const title = $root.querySelector('[data-testid=request-detail-title]')
        if (! title) { return }
        if (window.innerWidth < 1024) { $root.querySelector('[data-testid=request-detail]')?.scrollIntoView({ block: 'start' }) }
        title.focus({ preventScroll: true })
    })">
    <div class="flex flex-wrap items-end justify-between gap-x-7 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Solicitudes de cambio</h1>
            @if ($requests !== null)
                <p class="mt-1.5 text-sm text-neutral">Cambios de horario y de salón que piden los docentes y la coordinación en los grupos de {{ $departmentName }}{{ $modalityOptions->count() <= 1 && $shownModality ? ' · '.$shownModality->name : '' }}.</p>
            @endif
        </div>
        @if (($requests !== null && count($departments) > 1) || $canRequest || $modalityOptions->count() > 1)
            <div class="flex flex-wrap items-end gap-3 max-md:w-full">
                @if ($requests !== null && count($departments) > 1)
                    <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm" data-testid="department-select" />
                @endif
                <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality?->code" class="w-full md:w-56" />
                @if ($canRequest)
                    @if ($requestBlocked !== null)
                        <span class="tooltip tooltip-left max-md:w-full" data-tip="{{ $requestBlocked }}">
                            <button type="button" class="btn btn-outline h-10 max-md:h-11 max-md:w-full" data-testid="new-request" disabled aria-describedby="new-request-reason">
                                <x-icon name="o-plus" class="h-4 w-4" aria-hidden="true" />Nueva solicitud
                            </button>
                        </span>
                        <span id="new-request-reason" class="sr-only">{{ $requestBlocked }}</span>
                    @else
                        <x-button label="Nueva solicitud" icon="o-plus" class="btn-outline h-10 max-md:h-11 max-md:w-full" wire:click="openRequest" spinner="openRequest" data-testid="new-request" />
                    @endif
                @endif
            </div>
        @endif
    </div>

    @if ($requests !== null && $campusOptions->count() > 1)
        <div @class(['flex flex-wrap items-center gap-x-3 gap-y-2', 'max-lg:hidden' => $mobileDetail]) data-testid="requests-campus">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral" aria-hidden="true">Campus</span>
            <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" :counts="$campusCounts" all />
            <span class="text-sm text-neutral">Filtra las solicitudes por el campus de su grupo; la carga es de toda la modalidad.</span>
        </div>
    @endif

    @if ($requests === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="requests-empty">
            <p>{{ $message }}</p>
            @if ($canRequest && $requestBlocked === null)
                <p class="mt-1 text-sm">Con «Nueva solicitud» pides un cambio en un grupo de tu carrera.</p>
            @endif
        </div>
    @else
        @include('staffing::change-requests.banner', ['load' => $loadState['load'], 'approved' => $loadState['approved']])

        @if ($vetoReasons !== [])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="veto-reasons"
                :title="'La carga sigue en Ajustes: '.implode(' ', $vetoReasons)"
                description="La solicitud quedó aplicada. Cuando se resuelvan, la siguiente solicitud aplicada regresa la carga a Asignación." />
        @endif

        <div @class(['flex flex-wrap items-center gap-3', 'max-lg:hidden' => $mobileDetail]) role="search" aria-label="Filtrar solicitudes">
            @include('staffing::shared.segmented-filter', [
                'label' => 'Estado',
                'property' => 'status',
                'options' => ['' => 'Todas', 'pendientes' => 'Pendientes', 'aprobadas' => 'Aprobadas', 'rechazadas' => 'Rechazadas', 'aplicadas' => 'Aplicadas'],
                'current' => $status,
                'counts' => $counts,
                'testid' => 'status',
            ])
            <div class="flex w-full flex-wrap gap-3 md:ms-auto md:w-auto md:flex-nowrap">
                <div class="w-full md:w-40">
                    <x-select wire:model.live="kind" :options="[['id' => '', 'name' => 'Tipo'], ['id' => 'horario', 'name' => 'Horario'], ['id' => 'salon', 'name' => 'Salón']]" aria-label="Tipo de cambio" data-testid="kind-filter" />
                </div>
                <div class="w-full md:w-64">
                    <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Docente, grupo o materia" aria-label="Buscar por docente, grupo o materia" clearable data-testid="requests-search" />
                </div>
            </div>
        </div>

        <div class="lg:grid lg:grid-cols-[minmax(18rem,22rem)_minmax(0,1fr)] lg:items-start lg:gap-5 min-[1440px]:grid-cols-[minmax(20rem,26rem)_minmax(0,1fr)]">
            <section @class(['min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100', 'max-lg:hidden' => $mobileDetail]) aria-label="Solicitudes" data-testid="requests-list">
                @include('staffing::change-requests.list')
            </section>

            <section @class([
                'min-w-0 scroll-mt-(--shell-top) rounded-box border border-base-300 bg-base-100 lg:sticky lg:top-[calc(var(--shell-top)+1rem)] lg:max-h-[calc(100dvh-var(--shell-top)-2rem)] lg:overflow-y-auto',
                'max-lg:hidden' => ! $mobileDetail,
            ]) aria-label="Detalle de la solicitud" @if ($selected !== null) data-testid="request-detail" data-request-id="{{ $selected->id }}" @else data-testid="request-detail-empty" @endif>
                @if ($selected === null)
                    <p class="px-5 py-12 text-center text-neutral">{{ $requests->isEmpty() ? 'Cuando alguien pida un cambio en un grupo del departamento, aquí verás su detalle.' : 'Elige una solicitud de la lista.' }}</p>
                @else
                    @include('staffing::change-requests.detail', ['request' => $selected])
                @endif
            </section>
        </div>
    @endif

    @if ($canRequest)
        <x-modal wire:model="showRequest" box-class="max-w-xl" aria-labelledby="request-title" data-testid="request-modal">
            @if ($showRequest)
                <form wire:submit="sendRequest" class="space-y-5" novalidate>
                    <h2 id="request-title" class="text-xl font-bold">Nueva solicitud de cambio</h2>

                    @include('staffing::change-requests.group-picker')

                    @include('staffing::change-requests.request-fields')

                    <div class="flex flex-wrap justify-end gap-2 border-t border-base-300 pt-4">
                        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" wire:click="$set('showRequest', false)" />
                        <x-button label="Enviar solicitud" type="submit" icon="o-paper-airplane" class="btn-primary max-md:h-11 max-md:min-h-11" spinner="sendRequest" data-testid="send-request" />
                    </div>
                </form>
            @endif
        </x-modal>
    @endif
</div>
