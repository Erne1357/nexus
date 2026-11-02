{{--
    Ficha del periodo (DESIGN.md, "Ficha del periodo"), la maqueta B «Línea de tiempo»: un enlace de regreso a Periodos,
    el encabezado con el nombre, el código en Mono y «Actual», las fechas y, a la derecha, «Editar» y «Marcar como
    actual» (deshabilitado con su motivo en el periodo actual); y la sección «Calendario» con la línea de tiempo de las
    etapas (core::terms.timeline). Tocar una etapa abre su panel lateral, un x-drawer: fechas generales y, por cada
    campus activo, una ventana propia opcional. «Editar» abre el mismo formulario que la tabla de periodos.
--}}
<div class="space-y-6">
    <a href="{{ route('core.terms') }}" wire:navigate data-testid="back-to-terms" class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="h-4 w-4" />Periodos
    </a>

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.625rem] font-bold leading-tight md:text-3xl">
                {{ $term->name }}
                <span class="rounded border border-base-300 bg-base-100 px-2 py-0.5 font-mono text-xl font-semibold tabular-nums">{{ $term->code }}</span>
                @if ($term->is_current)
                    <x-core::status-badge tone="success" label="Actual" />
                @endif
            </h1>
            <p class="mt-2 font-medium">{{ $term->starts_on->format('d/m/Y') }} al {{ $term->ends_on->format('d/m/Y') }}</p>
        </div>

        <div class="flex gap-2 max-md:w-full">
            @if ($canUpdate)
                <x-button label="Editar" icon="o-pencil-square" class="btn-outline max-md:flex-1" wire:click="edit" spinner="edit" data-testid="edit-term" />
            @else
                <span class="tooltip max-md:flex-1" data-tip="Solo el administrador o la subdirección editan periodos.">
                    <x-button label="Editar" icon="o-pencil-square" class="btn-outline w-full" disabled />
                </span>
            @endif

            @if ($term->is_current)
                <span class="tooltip max-md:flex-1" data-tip="Ya es el periodo actual.">
                    <x-button label="Marcar como actual" icon="o-check" class="btn-outline w-full" disabled data-testid="make-current" />
                </span>
            @elseif ($canMakeCurrent)
                <x-button
                    label="Marcar como actual"
                    icon="o-check"
                    class="btn-outline max-md:flex-1"
                    wire:click="makeCurrent"
                    wire:confirm="¿Marcar el periodo {{ $term->code }} como el actual? Será el periodo de omisión de la credencial."
                    spinner="makeCurrent"
                    data-testid="make-current"
                />
            @else
                <span class="tooltip max-md:flex-1" data-tip="Solo el administrador o la subdirección marcan el periodo actual.">
                    <x-button label="Marcar como actual" icon="o-check" class="btn-outline w-full" disabled data-testid="make-current" />
                </span>
            @endif
        </div>
    </div>

    <section aria-labelledby="term-calendar-title" class="space-y-3">
        <h2 id="term-calendar-title" class="text-xl font-bold leading-tight">Calendario</h2>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            @include('core::terms.timeline')
        </div>
    </section>

    <x-modal wire:model="showModal" title="Editar periodo" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            @include('core::terms.form-fields')

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="stage-drawer-title" data-testid="stage-drawer">
        @if ($editing !== null)
            @include('core::terms.stage-panel')
        @endif
    </x-drawer>
</div>
