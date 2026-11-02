{{--
    Propuesta de carrera (tarea 2.7): encabezado con la carrera, el periodo y el estado; una alerta por cada
    departamento que regresó su parte; los totales; el resumen de departamentos (elegir uno resalta sus materias, en
    las dos vistas); y la vista que eligió el usuario, la retícula por semestre (maqueta C) o la tabla con captura
    en línea (maqueta B). Una materia de la retícula se corrige en un panel a la derecha. El envío pide confirmación
    con los totales y avisa, sin bloquear, de los catálogos sin validar. Todo lo que se pinta viene de
    CareerProposalQuery::overview.

    Con volumen (DESIGN.md, "Propuesta de carrera"): los departamentos son un riel a la izquierda solo desde 1700 px;
    debajo, una franja de chips sobre la vista, para que los nueve semestres quepan desde 1366 px. Al cerrar el panel
    o un diálogo, el foco vuelve al mosaico o al botón que lo abrió (`data-opener`).
--}}
<div class="relative space-y-5 overflow-x-clip" x-data="{ opener: null }"
    x-init="['showDrawer', 'showSubmit'].forEach((property) => $wire.$watch(property, (open) => { if (! open) { $nextTick(() => { if (opener?.isConnected) { opener.focus(); } }); } }))"
    x-on:click.capture="opener = $event.target.closest('[data-opener]') ?? opener">
    @if ($overview === null)
        <div>
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Propuesta de carrera</h1>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="proposal-empty">{{ $message }}</div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xl font-bold leading-tight md:text-3xl">
                    Propuesta de carrera
                    <x-core::status-badge :tone="$proposal->status->tone()" :label="$proposal->status->label()" data-testid="proposal-status" />
                </h1>
                <p class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-sm text-neutral" data-testid="proposal-meta">
                    <span class="font-medium text-base-content"><span class="font-mono">{{ $overview->proposal->career->code }}</span> · {{ $overview->proposal->career->name }}</span>
                    <span>{{ $overview->proposal->campus->name }}</span>
                    <span>{{ $overview->proposal->term->name }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                @if (count($careers) > 1)
                    <x-select label="Carrera" wire:model.live="careerId" :options="$careers" option-value="id" option-label="name" class="select-sm" data-testid="proposal-career" />
                @endif
                @if ($canSubmit)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        @if ($submitReason)
                            <span id="submit-reason" class="text-sm text-neutral" data-testid="submit-reason">{{ $submitReason }}</span>
                        @endif
                        <x-button :label="$proposal->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned ? 'Reenviar propuesta' : 'Enviar propuesta'" icon="o-paper-airplane" class="btn-primary"
                            wire:click="confirmSubmit" spinner="confirmSubmit" :disabled="! $proposal->status->isEditable()" :aria-describedby="$submitReason ? 'submit-reason' : null" data-opener data-testid="submit-proposal" />
                    </div>
                @endif
            </div>
        </div>

        @if ($returnedParts !== [])
            <div class="space-y-2" data-testid="returned-parts">
                @foreach ($returnedParts as $part)
                    <x-alert icon="o-arrow-uturn-left" class="alert-error alert-soft items-start text-sm" wire:key="returned-{{ $part['department']->code }}" data-testid="returned-{{ $part['department']->code }}">
                        <span><span class="font-semibold">{{ $part['department']->name }} regresó su parte</span>@if ($part['comment']): “{{ $part['comment'] }}”@endif · {{ $part['subjects'] }} {{ $part['subjects'] === 1 ? 'materia' : 'materias' }}</span>
                        <x-slot:actions>
                            <x-button label="Ver" class="btn-sm btn-ghost" wire:click="showDepartment('{{ $part['department']->code }}')" aria-label="Ver las materias de {{ $part['department']->name }}" />
                        </x-slot:actions>
                    </x-alert>
                @endforeach
            </div>
        @endif

        @if ($view !== 'tabla')
            @include('academic-load::proposal.totals')
        @endif

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

        <div class="min-[1700px]:grid min-[1700px]:grid-cols-[15rem_minmax(0,1fr)] min-[1700px]:items-start min-[1700px]:gap-5 max-[1699px]:space-y-4">
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
                @include('academic-load::proposal.item-panel', ['item' => $selected, 'lock' => isset($locks[$selected->id]) ? $locks[$selected->id]->message($selected->subject->department->code) : null])
            @endif
        </x-drawer>
    @endif
</div>
