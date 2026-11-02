{{--
    Bandeja del departamento (tarea 2.8, maqueta C de dos paneles): encabezado con el departamento, el campus, el
    periodo y el estado de la carga; el aviso de HC-14; a la izquierda las carreras con el estado de su parte y, a
    la derecha, lo que pide la elegida con la sugerencia de paralelos y sus botones. Desde lg los dos paneles van
    juntos; debajo, la lista y el detalle van por separado. Todo lo que se pinta viene de DepartmentInboxQuery.
--}}
<div class="space-y-5">
    @if ($inbox === null)
        <div>
            <h1 class="text-[1.625rem] font-bold leading-tight md:text-3xl">Bandeja del departamento</h1>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="inbox-empty">{{ $message }}</div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h1 class="text-[1.625rem] font-bold leading-tight md:text-3xl">Bandeja del departamento</h1>
                <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                    <span class="font-medium text-base-content" data-testid="inbox-department-name">{{ $inbox->department->name }}</span>
                    <span>{{ $inbox->campus->name }}</span>
                    <span class="font-mono">{{ $inbox->term->code }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                @if (count($departments) > 1)
                    <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm" data-testid="inbox-department" />
                @endif
                <div class="flex flex-col gap-1.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5">
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carga del departamento</span>
                    <span class="flex flex-wrap items-center gap-3">
                        @if ($inbox->load === null)
                            <x-core::status-badge tone="draft" label="Sin carga" data-testid="load-status" />
                        @else
                            <x-core::status-badge :tone="$inbox->load->status->tone()" :label="$inbox->load->status->label()" data-testid="load-status" />
                        @endif
                        <span class="text-sm text-neutral"><span class="font-mono font-semibold text-base-content">{{ $acceptedCount }}</span> de {{ $submittedCount }} {{ $submittedCount === 1 ? 'carrera aceptada' : 'carreras aceptadas' }}</span>
                    </span>
                </div>
            </div>
        </div>

        @if ($inbox->pendingCatalogs !== [])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="pending-catalogs"
                :title="'Faltan por validar '.collect($inbox->pendingCatalogs)->map(fn ($catalog) => mb_strtolower($catalog->label()))->join(', ', ' y ').'. No se puede aceptar hasta validarlos; regresar sí.'">
                @if ($catalogsUrl !== null)
                    <x-button label="Validar catálogos" icon="o-check-badge" class="btn-sm btn-ghost" link="{{ $catalogsUrl }}" data-testid="validate-catalogs" />
                @endif
            </x-alert>
        @endif

        <div class="lg:grid lg:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] lg:items-start lg:gap-5">
            <section @class(['min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100', 'max-lg:hidden' => $mobileDetail]) data-testid="inbox-list" aria-label="Carreras">
                <div role="group" aria-label="Filtro" class="flex gap-2 border-b border-base-300 px-3 py-3">
                    <button type="button" wire:click="$set('filter', 'todas')" aria-pressed="{{ $filter === 'todas' ? 'true' : 'false' }}" data-testid="filter-all"
                        @class(['btn btn-sm', 'btn-primary' => $filter === 'todas'])>Todas <span class="font-mono">{{ count($rows) }}</span></button>
                    <button type="button" wire:click="$set('filter', 'pendientes')" aria-pressed="{{ $filter === 'pendientes' ? 'true' : 'false' }}" data-testid="filter-pending"
                        @class(['btn btn-sm', 'btn-primary' => $filter === 'pendientes'])>Pendientes <span class="font-mono">{{ $pendingCount }}</span></button>
                </div>
                @include('academic-load::inbox.list')
            </section>

            <section @class(['min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100', 'max-lg:hidden' => ! $mobileDetail]) data-testid="inbox-detail" aria-label="Detalle de la carrera">
                @if ($selected === null || $submittedCount === 0)
                    <p class="px-5 py-8 text-center text-neutral" data-testid="inbox-empty-careers">Ninguna carrera ha enviado su propuesta con materias de este departamento.</p>
                @else
                    @include('academic-load::inbox.detail')
                @endif
            </section>
        </div>

        <x-modal wire:model="showAccept" box-class="max-w-md" aria-labelledby="accept-title" data-testid="accept-modal">
            @if ($showAccept && $target !== null)
                @include('academic-load::inbox.accept')
            @endif
        </x-modal>

        <x-modal wire:model="showReturn" box-class="max-w-lg" aria-labelledby="return-title" data-testid="return-modal">
            @if ($showReturn && $target !== null)
                @include('academic-load::inbox.return')
            @endif
        </x-modal>
    @endif
</div>
