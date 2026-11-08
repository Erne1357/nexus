{{--
    Solicitudes de cambio (tarea 3.11, maqueta A «Lista y detalle»): encabezado con el departamento (selector si hay
    varios), el aviso con el estado de la carga del departamento, los filtros (estado con sus cifras, tipo y texto) y
    los dos paneles: la lista a la izquierda y el detalle fijo a la derecha. Desde lg van juntos; debajo, la lista y el
    detalle van por separado (el detalle ocupa la pantalla, con «Solicitudes» para regresar). Todo lo que se pinta viene
    de ChangeRequestsQuery.
--}}
<div class="space-y-4" x-on:request-detail-opened.window="$nextTick(() => {
        const title = $root.querySelector('[data-testid=request-detail-title]')
        if (! title) { return }
        if (window.innerWidth < 1024) { $root.querySelector('[data-testid=request-detail]')?.scrollIntoView({ block: 'start' }) }
        title.focus({ preventScroll: true })
    })">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Solicitudes de cambio</h1>
            @if ($requests !== null)
                <p class="mt-1.5 text-sm text-neutral">Cambios de horario y de salón que piden los docentes y la coordinación en los grupos de {{ $departmentName }}.</p>
            @endif
        </div>
        @if ($requests !== null && count($departments) > 1)
            <div class="w-full md:w-72">
                <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" data-testid="department-select" />
            </div>
        @endif
    </div>

    @if ($requests === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="requests-empty">{{ $message }}</div>
    @else
        @include('staffing::change-requests.banner', ['load' => $loadState['load'], 'approved' => $loadState['approved']])

        @if ($vetoReasons !== [])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="veto-reasons"
                :title="'La carga sigue en Ajustes: '.implode(' ', $vetoReasons)"
                description="La solicitud quedó aplicada. Cuando se resuelvan, la siguiente solicitud aplicada regresa la carga a Asignación." />
        @endif

        <div class="flex flex-wrap items-end gap-3" role="search" aria-label="Filtrar solicitudes">
            <div role="group" aria-label="Estado" class="flex flex-wrap gap-1.5">
                @foreach (['' => 'Todas', 'pendientes' => 'Pendientes', 'aprobadas' => 'Aprobadas', 'rechazadas' => 'Rechazadas', 'aplicadas' => 'Aplicadas'] as $value => $label)
                    <button type="button" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" data-testid="status-{{ $value === '' ? 'todas' : $value }}"
                        @class(['btn btn-sm h-10 max-md:h-11 max-md:min-h-11', 'btn-primary' => $status === $value, 'btn-outline border-base-300' => $status !== $value])>
                        {{ $label }} <span class="font-mono tabular-nums opacity-85">{{ $counts[$value] }}</span>
                    </button>
                @endforeach
            </div>
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
</div>
