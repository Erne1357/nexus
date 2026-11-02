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

    <section aria-labelledby="term-campuses-title" class="space-y-3">
        <h2 id="term-campuses-title" class="text-xl font-bold leading-tight">Campus</h2>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100 divide-y divide-base-300" data-testid="term-campuses">
            @foreach ($campusRows as $row)
                @php($campus = $row['campus'])
                @php($opening = $row['opening'])
                <div
                    wire:key="campus-row-{{ $campus->id }}"
                    data-testid="campus-{{ $campus->code }}"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3.5 md:grid-cols-[48px_minmax(0,1fr)_auto_auto]"
                >
                    <span @class(['font-mono text-[15px] font-semibold', 'text-neutral' => $opening === null])>{{ $campus->code }}</span>

                    <div class="col-span-full row-start-2 min-w-0 md:col-span-1 md:row-start-auto">
                        <b @class(['block text-[15px] font-bold', 'text-neutral' => $opening === null])>{{ $campus->name }}</b>
                        <small class="block text-[13.5px] text-neutral">
                            @if ($opening === null)
                                No abierto en este periodo
                            @else
                                @php($openedOn = $opening->opened_at->timezone('America/Ciudad_Juarez'))
                                {{ 'Abierto el '.\App\Modules\Core\Support\StageTimeline::dateLabel($openedOn).' '.$openedOn->year.($opening->opener ? ' por '.$opening->opener->name : '').($campus->trashed() ? ' · Campus borrado' : (! $campus->is_active ? ' · Campus inactivo' : '')) }}
                            @endif
                        </small>
                    </div>

                    @if ($opening === null)
                        <x-core::status-badge tone="neutral" label="No abierto" class="md:col-start-3" />
                    @else
                        <x-core::status-badge tone="success" label="Abierto" class="md:col-start-3" />
                    @endif

                    <div class="col-span-full row-start-3 flex justify-end md:col-span-1 md:col-start-4 md:row-start-auto md:min-w-[156px]">
                        @if (! $row['actionable'])
                            {{-- Campus inactivo o borrado: solo lectura, sin acciones. --}}
                        @elseif ($opening === null)
                            @if ($canOpen)
                                <x-button
                                    label="Abrir en {{ $campus->name }}"
                                    class="btn-outline btn-sm max-md:flex-1"
                                    wire:click="openIn('{{ $campus->id }}')"
                                    wire:confirm="¿Abrir el periodo {{ $term->code }} en {{ $campus->name }}? Se crean la carga académica y las revisiones de catálogo de cada departamento activo."
                                    spinner="openIn('{{ $campus->id }}')"
                                    data-testid="open-{{ $campus->code }}"
                                />
                            @else
                                <span class="tooltip tooltip-left max-md:flex-1" data-tip="Solo el administrador o la subdirección abren periodos.">
                                    <x-button label="Abrir en {{ $campus->name }}" class="btn-outline btn-sm w-full" disabled data-testid="open-{{ $campus->code }}" />
                                </span>
                            @endif
                        @else
                            <x-core::row-menu :name="$campus->code">
                                @if ($canOpen)
                                    <x-core::row-menu-item
                                        label="Completar apertura"
                                        wire:click="completeIn('{{ $campus->id }}')"
                                        :spinner="'completeIn(\''.$campus->id.'\')'"
                                        :data-testid="'complete-'.$campus->code"
                                    />
                                @else
                                    <x-core::row-menu-item label="Completar apertura" reason="Solo el administrador o la subdirección abren periodos." disabled />
                                @endif
                            </x-core::row-menu>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

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
