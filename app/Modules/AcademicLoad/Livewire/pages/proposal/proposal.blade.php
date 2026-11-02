{{--
    Propuesta de carrera (tarea 2.7): encabezado con la carrera, el periodo y el estado; los totales; el resumen de
    departamentos (elegir uno resalta sus materias, en las dos vistas); y la vista que eligió el usuario, la
    retícula por semestre (maqueta C) o la tabla con captura en línea (maqueta B). Una materia de la retícula se
    corrige en un panel a la derecha. El envío pide confirmación con los totales y avisa, sin bloquear, de los
    catálogos sin validar. Todo lo que se pinta viene de CareerProposalQuery::overview.
--}}
<div class="space-y-5">
    @if ($overview === null)
        <div>
            <h1 class="text-[1.625rem] font-bold leading-tight md:text-3xl">Propuesta de carrera</h1>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="proposal-empty">{{ $message }}</div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
                    Propuesta de carrera
                    <x-core::status-badge :tone="$proposal->status->tone()" :label="$proposal->status->label()" data-testid="proposal-status" />
                </h1>
                <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                    <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $overview->proposal->career->code }}</span> · {{ $overview->proposal->career->name }}</span></span>
                    <span>Campus <span class="font-medium text-base-content">{{ $overview->proposal->campus->name }}</span></span>
                    <span>Periodo <span class="font-mono font-medium text-base-content">{{ $overview->proposal->term->code }}</span></span>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                @if (count($careers) > 1)
                    <x-select label="Carrera" wire:model.live="careerId" :options="$careers" option-value="id" option-label="name" class="select-sm" data-testid="proposal-career" />
                @endif
                @if ($canSubmit)
                    <x-button :label="$proposal->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned ? 'Reenviar propuesta' : 'Enviar propuesta'" icon="o-paper-airplane" class="btn-primary"
                        wire:click="confirmSubmit" spinner="confirmSubmit" :disabled="! $proposal->status->isEditable()" data-testid="submit-proposal" />
                @endif
            </div>
        </div>

        <div class="grid grid-cols-3 overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="proposal-totals">
            @foreach ([['Materias con grupos', $overview->totals['subjects']], ['Grupos', $overview->totals['groups']], ['Alumnos esperados', $overview->totals['expected']]] as [$label, $value])
                <div class="flex flex-col gap-1 border-base-300 px-3 py-3 sm:px-5 [&:nth-child(n+2)]:border-l">
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                    <span class="font-mono text-2xl font-semibold tabular-nums">{{ $value }}</span>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="Vista" class="join">
                <button type="button" wire:click="setView('reticula')" aria-pressed="{{ $view === 'reticula' ? 'true' : 'false' }}" data-testid="view-grid"
                    @class(['btn btn-sm join-item', 'btn-primary' => $view === 'reticula'])><x-icon name="o-squares-2x2" class="w-4 h-4" />Retícula</button>
                <button type="button" wire:click="setView('tabla')" aria-pressed="{{ $view === 'tabla' ? 'true' : 'false' }}" data-testid="view-table"
                    @class(['btn btn-sm join-item', 'btn-primary' => $view === 'tabla'])><x-icon name="o-table-cells" class="w-4 h-4" />Tabla</button>
            </div>
            @if ($view === 'tabla' && $department !== '')
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="onlyDepartment" data-testid="only-department" />
                    Mostrar solo {{ $department }}
                </label>
            @endif
        </div>

        <div class="xl:grid xl:grid-cols-[15rem_minmax(0,1fr)] xl:items-start xl:gap-5 max-xl:space-y-4">
            @include('academic-load::proposal.departments')

            <div class="min-w-0">
                @if ($view === 'tabla')
                    @include('academic-load::proposal.table')
                @else
                    @include('academic-load::proposal.grid')
                @endif
            </div>
        </div>

        {{-- Los modales y el panel van en la página y no dentro de otra caja: el panel se desliza con transform. --}}
        @if ($canSubmit)
            <x-modal wire:model="showSubmit" box-class="max-w-lg" aria-labelledby="submit-title" data-testid="submit-modal">
                @if ($showSubmit)
                    @include('academic-load::proposal.submit')
                @endif
            </x-modal>
        @endif

        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="proposal-drawer-title" data-testid="proposal-drawer">
            @if ($selected !== null)
                @include('academic-load::proposal.item-panel', ['item' => $selected, 'lock' => $locks[$selected->id] ?? null])
            @endif
        </x-drawer>
    @endif
</div>
