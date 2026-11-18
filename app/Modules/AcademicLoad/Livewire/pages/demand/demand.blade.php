{{--
    Demanda por materia (tarea 5.7; DESIGN.md, «Demanda por materia»: maqueta C «Materias × carreras», elegida por el
    usuario el 17 de noviembre de 2026). Encabezado con el departamento (o la carrera en la lente de carrera, «Solo
    consulta»), la modalidad y el periodo; la proyección, cuántas carreras enviaron y el umbral que se resalta; cuatro
    cifras de todo el Tec; la búsqueda, el filtro «Todas · Con diferencia · Sin propuesta» y el de campus; la matriz
    materias × carreras desde 768 px (cada celda «pedidos/sugeridos», resaltada con su icono si se aparta del umbral o
    punteada con el reloj si la carrera no ha enviado; la materia fija a la izquierda y «Todo el Tec» a la derecha) y,
    debajo, renglones apilados por materia con las carreras que la llevan. Tocar una materia abre su drawer. Todo lo que
    se pinta viene de DemandBoardQuery.
--}}
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[1.625rem] font-bold leading-tight md:text-3xl">
                Demanda por materia
                @if ($board?->isCareerLens())
                    <x-core::status-badge tone="neutral" icon="o-eye" label="Solo consulta" class="text-sm font-medium" data-testid="demand-read-only" />
                @endif
            </h1>
            @if ($board)
                <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-neutral" data-testid="demand-meta">
                    @if ($board->isCareerLens())
                        <span class="font-medium text-base-content"><span class="font-mono">{{ $board->career->code }}</span> · {{ $board->career->name }}</span>
                    @else
                        <span class="font-medium text-base-content"><span class="font-mono">{{ $board->department->code }}</span> · {{ $board->department->name }}</span>
                    @endif
                    @if ($modalityOptions->count() <= 1)
                        <span>{{ $board->modality->name }}</span>
                    @endif
                    <span>{{ $board->term->name }}</span>
                    {{-- De toda la lente, sin el filtro de departamento: lo que filtra se dice en las cifras. --}}
                    <span data-testid="demand-scope">{{ $lensSubjects }} {{ $lensSubjects === 1 ? 'materia' : 'materias' }} {{ $board->isCareerLens() ? 'de '.$lensDepartments.' '.($lensDepartments === 1 ? 'departamento' : 'departamentos') : 'en '.count($board->careers).' '.(count($board->careers) === 1 ? 'carrera' : 'carreras') }}</span>
                </p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-neutral" data-testid="demand-run">
                    <x-icon name="o-sparkles" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    @if ($board->computedAt)
                        <span>Proyección del {{ $board->computedAt->setTimezone(config('app.timezone'))->format('d/m/Y') }} a las {{ $board->computedAt->setTimezone(config('app.timezone'))->format('G:i') }}</span>
                    @else
                        <span>Todavía no hay proyección</span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <span>Propuestas enviadas: <b class="font-semibold text-base-content"><span class="font-mono">{{ $board->sentCareers() }}</span> de <span class="font-mono">{{ count($board->careers) }}</span></b> {{ count($board->careers) === 1 ? 'carrera' : 'carreras' }}</span>
                    <span aria-hidden="true">·</span>
                    <span>Resalta lo que se aparta {{ $board->settings->highlightThreshold }} % o más</span>
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-end gap-3 max-md:w-full">
            <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" class="max-md:w-full md:w-56" />
            @if (count($departments) > 1)
                <x-select label="Departamento" wire:model.live="departmentCode" :options="$departments" option-value="code" option-label="name" class="max-md:h-11 md:w-80" data-testid="demand-department" />
            @endif
            {{-- Con las dos lentes, «Ver por departamento» (vacío) vuelve a la del departamento y una carrera abre la suya. --}}
            @if (count($careers) > 1 || ($careers !== [] && $canDepartmentLens))
                <x-select label="Carrera" wire:model.live="careerCode" :options="$careers" option-value="code" option-label="name" :placeholder="$canDepartmentLens ? 'Ver por departamento' : null" placeholder-value="" class="max-md:h-11 md:w-80" data-testid="demand-career" />
            @endif
        </div>
    </div>

    @if ($board === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="demand-empty">{{ $message }}</div>
    @else
        @include('academic-load::demand.figures')

        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-3" data-testid="demand-toolbar">
            <div class="w-full md:w-64">
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Clave o materia" aria-label="Buscar por clave o materia" clearable class="max-md:h-11" data-testid="demand-search" />
            </div>
            @if (count($filterDepartments) > 1)
                <div class="w-full md:w-52">
                    <x-select wire:model.live="departmentCode" aria-label="Departamento de la materia" :options="collect($filterDepartments)->map(fn ($department) => ['id' => $department->code, 'name' => $department->code.' · '.$department->name])->all()" placeholder="Departamento" placeholder-value="" class="max-md:h-11" data-testid="demand-filter-department" />
                </div>
            @endif
            <div role="group" aria-label="Materias" class="join grid w-full grid-cols-3 md:ms-auto md:flex md:w-auto" data-testid="demand-filter">
                @foreach (['' => ['Todas', 'all'], 'diferencia' => ['Con diferencia', 'difference'], 'sin-propuesta' => ['Sin propuesta', 'pending']] as $option => [$optionLabel, $countKey])
                    <button type="button" wire:click="$set('filter', '{{ $option }}')" aria-pressed="{{ $filter === $option ? 'true' : 'false' }}" data-testid="demand-filter-{{ $option ?: 'todas' }}"
                        @class([
                            'btn join-item px-2 max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:flex-col max-md:gap-0 max-md:py-1 max-md:leading-tight md:gap-1.5 md:whitespace-nowrap md:px-3',
                            'btn-primary' => $filter === $option,
                        ])><span class="max-md:max-w-full max-md:truncate">{{ $optionLabel }}</span> <span class="font-mono text-[12.5px] tabular-nums opacity-85">{{ $filterCounts[$countKey] }}</span></button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="demand-campus">
            @if ($campusOptions->count() > 1)
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden" aria-hidden="true">Campus</span>
                <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" :counts="$figures['countedByCampus']" :total="$figures['counted']" all class="max-md:w-full" />
            @endif
            <span class="text-sm text-neutral">Cada celda: <b class="font-mono font-semibold text-base-content">pedidos</b><span class="font-mono">/sugeridos</span> de la carrera</span>
            @if (count($visible) !== $filterCounts['all'])
                <span class="text-sm text-neutral md:ms-auto" data-testid="demand-shown">Mostrando <span class="font-mono">{{ count($visible) }}</span> de <span class="font-mono">{{ $filterCounts['all'] }}</span> · <button type="button" wire:click="clearFilters" class="link link-hover font-medium text-base-content" data-testid="demand-clear">Quitar filtros</button></span>
            @endif
        </div>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="demand-matrix">
            @if ($board->subjects === [])
                <div class="px-5 py-8 text-center" data-testid="demand-no-subjects">
                    <p class="font-semibold">Todavía no hay demanda que mostrar.</p>
                    <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">La proyección de {{ $board->modality->name }} en {{ $board->term->name }} no trae materias {{ $board->isCareerLens() ? 'de esta carrera' : 'de este departamento' }} con alumnos ni grupos.</p>
                </div>
            @elseif ($visible === [])
                <p class="px-5 py-8 text-center font-semibold" data-testid="demand-no-match">Ninguna materia con esos filtros.</p>
            @else
                @include('academic-load::demand.matrix')
                @include('academic-load::demand.stack')
            @endif
            @include('academic-load::demand.legend')
        </div>
    @endif

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-3xl p-0!" role="dialog" aria-modal="true" aria-labelledby="demand-drawer-title" data-testid="demand-drawer">
        @if ($board !== null && $drawer !== null)
            @include('academic-load::demand.drawer')
        @elseif ($showDrawer)
            {{-- La materia salió del tablero entre una petición y otra (se recalculó la proyección): se dice, sin cerrar solo. --}}
            <div class="flex h-dvh flex-col" data-testid="demand-panel-gone">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <h2 id="demand-drawer-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">Materia sin demanda</h2>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>
                <p class="px-5 py-6 text-neutral">{{ $subjectCode }} ya no está en el tablero: la proyección o las propuestas cambiaron.</p>
            </div>
        @endif
    </x-drawer>
</div>
