{{--
    Ficha del periodo (DESIGN.md, "Ficha del periodo"), la maqueta B «Línea de tiempo»: un enlace de regreso a Periodos,
    el encabezado con el nombre, el código en Mono y «Actual», las fechas y, a la derecha, «Editar» y «Marcar como
    actual» (deshabilitado con su motivo en el periodo actual); el resumen de campus en un renglón («Abierto en» con
    los códigos y un «Abrir en <campus>» por cada campus activo sin abrir, o un solo menú «Abrir en…» si faltan más de
    dos); la sección «Calendario» con la línea de tiempo de las etapas (core::terms.timeline); y al final la lista
    «Campus» con quién abrió y cuándo, y «Completar apertura». Tocar una etapa abre su panel lateral, un x-drawer: fechas generales y, por cada
    campus activo, una ventana propia opcional. «Editar» abre el mismo formulario que la tabla de periodos.
--}}
<div class="space-y-6">
    <a href="{{ route('core.terms') }}" wire:navigate data-testid="back-to-terms" class="inline-flex items-center gap-1.5 font-medium text-primary max-md:min-h-11">
        <x-icon name="o-chevron-left" class="h-4 w-4" />Periodos
    </a>

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.625rem] font-bold leading-tight md:text-3xl">
                {{ $term->name }}
                <span class="rounded border border-base-300 bg-base-100 px-2 py-0.5 font-mono text-xl font-semibold tabular-nums">{{ $term->code }}</span>
                @if ($term->is_current)
                    <x-core::status-badge tone="primary" label="Actual" />
                @endif
            </h1>
            <p class="mt-2 font-medium">{{ $term->starts_on->format('d/m/Y') }} al {{ $term->ends_on->format('d/m/Y') }}</p>
        </div>

        <div class="flex gap-2 max-md:w-full">
            @if ($canUpdate)
                <x-button label="Editar" icon="o-pencil-square" class="btn-outline max-md:h-11" wire:click="edit" spinner="edit" data-testid="edit-term" />
            @else
                <span class="tooltip" data-tip="Solo el administrador o la subdirección editan periodos.">
                    <x-button label="Editar" icon="o-pencil-square" class="btn-outline max-md:h-11" disabled />
                </span>
            @endif

            @if ($term->is_current)
                <span class="tooltip max-md:min-w-52 max-md:flex-1" data-tip="Ya es el periodo actual.">
                    <x-button label="Marcar como actual" icon="o-check" class="btn-outline w-full max-md:h-11" disabled data-testid="make-current" />
                </span>
            @elseif ($canMakeCurrent)
                <x-button
                    label="Marcar como actual"
                    icon="o-check"
                    class="btn-outline max-md:h-11 max-md:min-w-52 max-md:flex-1"
                    wire:click="makeCurrent"
                    x-confirm="Será el periodo de omisión de la credencial."
                    data-confirm-title="¿Marcar el periodo {{ $term->code }} como el actual?"
                    data-confirm-button="Marcar como actual"
                    data-confirm-tone="warning"
                    spinner="makeCurrent"
                    data-testid="make-current"
                />
            @else
                <span class="tooltip max-md:min-w-52 max-md:flex-1" data-tip="Solo el administrador o la subdirección marcan el periodo actual.">
                    <x-button label="Marcar como actual" icon="o-check" class="btn-outline w-full max-md:h-11" disabled data-testid="make-current" />
                </span>
            @endif
        </div>
    </div>

    <section aria-label="Apertura por campus" class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="campus-summary">
        <span class="font-medium">Abierto en</span>

        @forelse ($openedRows as $row)
            <span
                wire:key="opened-chip-{{ $row['campus']->id }}"
                @class(['rounded border border-base-300 bg-base-100 px-2 py-0.5 font-mono text-[15px] font-semibold', 'text-neutral' => ! $row['actionable']])
                @if (! $row['actionable']) title="{{ $row['campus']->name }} · {{ $row['campus']->trashed() ? 'Campus borrado' : 'Campus inactivo' }}" @endif
                data-testid="opened-{{ $row['campus']->code }}"
            >{{ $row['campus']->code }}</span>
        @empty
            <span class="text-neutral" data-testid="opened-none">ningún campus</span>
        @endforelse

        @if ($pendingRows->count() > 2)
            @if ($canOpen)
                <details
                    class="dropdown"
                    x-data
                    x-on:click.outside="$el.removeAttribute('open')"
                    x-on:keydown.escape="$el.removeAttribute('open'); $el.querySelector('summary').focus()"
                    data-testid="open-menu"
                >
                    <summary class="btn btn-outline btn-sm max-md:h-11">Abrir en…<x-icon name="o-chevron-down" class="h-4 w-4" aria-hidden="true" /></summary>
                    <ul class="menu dropdown-content z-10 mt-1 w-max max-w-[calc(100vw-2rem)] rounded-box border border-base-300 bg-base-100 p-1 shadow-lg" x-on:click="$el.closest('details').removeAttribute('open')">
                        @foreach ($pendingRows as $row)
                            <li wire:key="open-item-{{ $row['campus']->id }}">
                                <button
                                    type="button"
                                    class="max-md:min-h-11"
                                    wire:click="openIn('{{ $row['campus']->id }}')"
                                    x-confirm="Se crean la carga académica y las revisiones de catálogo de cada departamento activo."
                                    data-confirm-title="¿Abrir el periodo {{ $term->code }} en {{ $row['campus']->name }}?"
                                    data-confirm-button="Abrir"
                                    data-confirm-tone="warning"
                                    data-testid="open-{{ $row['campus']->code }}"
                                >{{ $row['campus']->name }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @else
                <span class="tooltip" data-tip="Solo el administrador o la subdirección abren periodos.">
                    <x-button label="Abrir en…" class="btn-outline btn-sm max-md:h-11" disabled data-testid="open-menu" />
                </span>
            @endif
        @else
            @foreach ($pendingRows as $row)
                @if ($canOpen)
                    <x-button
                        wire:key="open-{{ $row['campus']->id }}"
                        label="Abrir en {{ $row['campus']->name }}"
                        class="btn-outline btn-sm max-md:h-11"
                        wire:click="openIn('{{ $row['campus']->id }}')"
                        x-confirm="Se crean la carga académica y las revisiones de catálogo de cada departamento activo."
                        data-confirm-title="¿Abrir el periodo {{ $term->code }} en {{ $row['campus']->name }}?"
                        data-confirm-button="Abrir"
                        data-confirm-tone="warning"
                        spinner="openIn('{{ $row['campus']->id }}')"
                        data-testid="open-{{ $row['campus']->code }}"
                    />
                @else
                    <span class="tooltip" data-tip="Solo el administrador o la subdirección abren periodos.">
                        <x-button label="Abrir en {{ $row['campus']->name }}" class="btn-outline btn-sm max-md:h-11" disabled data-testid="open-{{ $row['campus']->code }}" />
                    </span>
                @endif
            @endforeach
        @endif
    </section>

    <section aria-labelledby="term-calendar-title" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
            <h2 id="term-calendar-title" class="text-xl font-bold leading-tight">Calendario</h2>
            @if ($timeline->hasRange())
                @include('core::terms.copy-button')
            @endif
        </div>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            @include('core::terms.timeline')
        </div>
    </section>

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
                    <span @class(['font-mono text-[15px] font-semibold', 'text-neutral' => $opening === null || ! $row['actionable']])>{{ $campus->code }}</span>

                    <div class="col-span-full row-start-2 min-w-0 md:col-span-1 md:row-start-auto">
                        <b @class(['block text-base font-bold', 'text-neutral' => $opening === null || ! $row['actionable']])>{{ $campus->name }}</b>
                        <small class="block text-sm text-neutral">
                            @if ($opening === null)
                                No abierto en este periodo
                            @else
                                @php($openedOn = $opening->opened_at->timezone('America/Ciudad_Juarez'))
                                {{ 'Abierto el '.\App\Modules\Core\Support\StageTimeline::dateLabel($openedOn).' '.$openedOn->year.($opening->opener ? ' por '.$opening->opener->name : '') }}
                            @endif
                        </small>
                    </div>

                    @if ($opening === null)
                        <x-core::status-badge tone="neutral" label="No abierto" class="md:col-start-3" />
                    @elseif ($row['actionable'])
                        <x-core::status-badge tone="success" label="Abierto" class="md:col-start-3" />
                    @else
                        <x-core::status-badge tone="neutral" label="Abierto" class="md:col-start-3" />
                    @endif

                    <div class="col-span-full row-start-3 flex justify-end md:col-span-1 md:col-start-4 md:row-start-auto md:min-w-[156px]">
                        @if (! $row['actionable'])
                            <span class="text-sm text-neutral">{{ $campus->trashed() ? 'Campus borrado' : 'Campus inactivo' }}</span>
                        @elseif ($opening !== null)
                            <x-core::row-menu :name="$campus->code" class="max-md:[&>button]:min-h-11">
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

    <x-modal wire:model="showModal" title="Editar periodo" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            @include('core::terms.form-fields')

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showCopy" title="Copiar el calendario de otro periodo" box-class="max-w-xl" separator data-testid="copy-modal">
        <div class="space-y-4">
            <x-select
                label="Copiar de"
                wire:model.live="sourceId"
                :options="$copySources->map(fn ($source) => ['id' => $source->id, 'name' => $source->code.' · '.$source->name])->all()"
                placeholder="Elige un periodo"
                placeholder-value=""
                data-testid="copy-source"
            />

            @if ($copy !== null)
                <div class="space-y-3" data-testid="copy-preview">
                    <p class="font-medium">
                        @if ($copy['weeks'] === 0)
                            Las fechas quedan igual.
                        @else
                            Fechas corridas {{ $copy['weeks'] }} {{ $copy['weeks'] === 1 ? 'semana' : 'semanas' }} hacia {{ $copy['direction'] }}.
                        @endif
                    </p>

                    <dl class="divide-y divide-base-300 rounded-box border border-base-300">
                        @foreach ($copy['groups'] as $group)
                            <div class="grid gap-x-4 gap-y-1 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <dt class="font-bold">{{ $group['stage'] }}</dt>
                                <dd class="space-y-0.5">
                                    @foreach ($group['rows'] as $row)
                                        <span class="flex items-baseline justify-between gap-4 sm:justify-end">
                                            <span @class(['text-sm', 'font-mono font-semibold' => ! $row['general'], 'text-neutral' => $row['general']])>{{ $row['who'] }}</span>
                                            <span class="font-mono text-sm tabular-nums">{{ $row['dates'] }}</span>
                                        </span>
                                    @endforeach
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($copy['skipped'] !== [])
                        <p class="text-sm text-neutral" data-testid="copy-skipped">No se copian: {{ implode('; ', $copy['skipped']) }}</p>
                    @endif

                    @if ($copyReplace !== null)
                        <p class="text-sm text-neutral" data-testid="copy-replace">{{ $copyReplace['text'] }}</p>
                    @endif
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showCopy = false" class="max-md:h-11" />
            @if ($copyReplace !== null)
                <x-button
                    label="Reemplazar calendario"
                    icon="o-check"
                    class="btn-primary max-md:h-11"
                    wire:click="copyCalendar"
                    x-confirm="{{ $copyReplace['text'] }}"
                    data-confirm-title="¿Reemplazar el calendario del periodo?"
                    data-confirm-button="Reemplazar"
                    data-confirm-tone="warning"
                    :disabled="$copy === null"
                    spinner="copyCalendar"
                    data-testid="copy-confirm"
                />
            @else
                <x-button label="Copiar calendario" icon="o-check" class="btn-primary max-md:h-11" wire:click="copyCalendar" :disabled="$copy === null" spinner="copyCalendar" data-testid="copy-confirm" />
            @endif
        </x-slot:actions>
    </x-modal>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="stage-drawer-title" data-testid="stage-drawer">
        @if ($editing !== null)
            @include('core::terms.stage-panel')
        @endif
    </x-drawer>
</div>
