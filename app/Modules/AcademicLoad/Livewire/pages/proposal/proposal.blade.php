{{--
    Propuesta de carrera (tarea 2.7): encabezado con la carrera, el periodo y el estado; una alerta por cada
    departamento que regresó su parte; los totales; el resumen de departamentos (elegir uno resalta sus materias, en
    las dos vistas); y la vista que eligió el usuario, la retícula por semestre (maqueta C) o la tabla con captura
    en línea (maqueta B). Una materia de la retícula se corrige en un panel a la derecha. El envío pide confirmación
    con los totales y avisa, sin bloquear, de los catálogos sin validar. Todo lo que se pinta viene de
    CareerProposalQuery::overview.

    Con volumen (mismas medidas que "Retícula del alumno" y "Retícula del plan (editor)" de DESIGN.md): los departamentos son un riel a la izquierda solo desde 1700 px;
    debajo, una franja de chips sobre la vista, para que los nueve semestres quepan desde 1366 px. Al cerrar el panel
    o un diálogo, el foco vuelve al mosaico o al botón que lo abrió (`data-opener`). Los totales de la retícula van
    en el renglón de la vista y la alerta de cada departamento regresado es de un renglón, para que la retícula
    empiece alta en 1366 x 768. Dos eventos de la página se atienden aquí: `proposal-focus` («Ver» en la alerta)
    desplaza la vista a la materia, le da el foco y la marca un momento; `cell-saved` marca con una palomita la celda
    de la tabla que se guardó.
--}}
<div class="relative space-y-4 overflow-x-clip"
    x-data="{
        opener: null,
        reduced: matchMedia('(prefers-reduced-motion: reduce)').matches,
        mark(el, attribute, ms) {
            el.setAttribute(attribute, '');
            setTimeout(() => el.removeAttribute(attribute), ms);
        },
        found(id) {
            const el = $root.querySelector('[data-item=&quot;' + id + '&quot;]');
            if (! el) { return; }
            el.scrollIntoView({ block: 'center', inline: 'center', behavior: this.reduced ? 'auto' : 'smooth' });
            el.focus({ preventScroll: true });
            this.mark(el, 'data-found', 2500);
        },
        saved(detail) {
            const cell = $root.querySelector('[data-cell=&quot;' + detail.id + '.' + detail.field + '&quot;]');
            if (! cell) { return; }
            const status = cell.querySelector('[data-saved-status]');
            if (status) { status.textContent = 'Guardado'; setTimeout(() => status.textContent = '', 2000); }
            this.mark(cell, 'data-saved', 1800);
        },
    }"
    x-on:proposal-focus.window="$nextTick(() => found($event.detail.id))"
    x-on:cell-saved.window="$nextTick(() => saved($event.detail))"
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
            <div class="space-y-1.5" data-testid="returned-parts">
                @foreach ($returnedParts as $part)
                    @php
                        $partCode = $part['department']->code;
                        $total = count($part['items']);
                        $position = $returnedAt === null ? 0 : min($returnedAt, $total - 1);
                        $here = $department === $partCode && $returnedAt !== null && $total > 1;
                        $lead = $part['department']->name.' regresó su parte';
                        $rest = ($part['comment'] ? ": “{$part['comment']}”" : '').' · '.$part['subjects'].' '.($part['subjects'] === 1 ? 'materia' : 'materias');
                    @endphp
                    <x-alert icon="o-arrow-uturn-left" class="alert-error alert-soft min-h-0 items-center gap-x-2 py-1.5 text-sm" wire:key="returned-{{ $partCode }}" data-testid="returned-{{ $partCode }}">
                        <span class="min-w-0 md:truncate" title="{{ $lead.$rest }}"><span class="font-semibold">{{ $lead }}</span>{{ $rest }}</span>
                        <x-slot:actions>
                            <x-button :label="$here ? ($position + 1).' de '.$total.' ›' : 'Ver'" class="btn-xs btn-ghost" wire:click="showDepartment('{{ $partCode }}')"
                                :aria-label="$here ? 'Ir a la siguiente materia regresada de '.$part['department']->name.': '.($position + 1).' de '.$total : 'Ver las materias de '.$part['department']->name" data-testid="see-{{ $partCode }}" />
                        </x-slot:actions>
                    </x-alert>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <div role="group" aria-label="Vista" class="join">
                <button type="button" wire:click="setView('reticula')" aria-pressed="{{ $view === 'reticula' ? 'true' : 'false' }}" data-testid="view-grid"
                    @class(['btn btn-sm join-item', 'btn-primary' => $view === 'reticula'])><x-icon name="o-squares-2x2" class="w-4 h-4" />Retícula</button>
                <button type="button" wire:click="setView('tabla')" aria-pressed="{{ $view === 'tabla' ? 'true' : 'false' }}" data-testid="view-table"
                    @class(['btn btn-sm join-item', 'btn-primary' => $view === 'tabla'])><x-icon name="o-table-cells" class="w-4 h-4" />Tabla</button>
            </div>
            @if ($view !== 'tabla')
                @include('academic-load::proposal.totals')
            @endif
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
