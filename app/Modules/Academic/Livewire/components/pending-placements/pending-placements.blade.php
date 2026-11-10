{{--
    «Por validar» de un plan (maqueta B, `.impeccable/mocks/por-validar/b.html`): la bandeja y la retícula del plan.

    - La bandeja (data-testid="pending-tray"), con el tinte de advertencia: «Por validar N», la búsqueda y una ficha
      por materia: el nombre; la clave con horas de teoría, de práctica y créditos en Mono; y a la derecha su retícula
      del SII. La elegida lleva aria-pressed y el anillo `primary`. Sin pendientes, el estado vacío con la palomita.
      Desde 1024 px va a la izquierda de la retícula, fija mientras se desplaza la página y con su lista desplazable;
      debajo es una tira horizontal sobre los semestres apilados.
    - La retícula, con las piezas de Core (core::semester-grid), de solo lectura: los mosaicos no son botones. Con una
      ficha elegida, cada semestre que se ofrece lleva «Colocar aquí» bajo su encabezado (el slot `below`, en todos a la
      vez para que los encabezados midan lo mismo). El mosaico recién colocado lleva data-new y el anillo de éxito.
    - El panel (x-drawer a la derecha, a pantalla completa en celular): «Agregar a la retícula», el nombre y la clave;
      los errores que no son del semestre; créditos, horas, especialidad y retícula del SII; el semestre, con
      «Sugerido: N» debajo si la migración lo sugiere; la carga del semestre antes y después; y al pie «Cancelar» y
      «Agregar a la retícula».
    - Semestre sugerido (D18): la ficha dice «Sugerido: N» y, si ese semestre se ofrece, elegirla abre el panel con él
      elegido (aria-haspopup); si todavía no se ofrece, lo dice. En una retícula histórica la sugerencia no se usa, y
      con pendientes, arriba de todo un aviso `alert-info` dice por qué no hay trabajo.
    - Requisitos que esperan (seriación del SII): en el panel, debajo de los datos, qué requisitos entran con la materia
      a la seriación al asignarle semestre (data-testid="pending-prerequisites"), con los que no entrarían por un ciclo
      marcados en `text-warning`; cuáles esperan a que se valide la otra materia (data-testid="pending-prerequisites-waiting"),
      con la clave en Mono y su tipo; y la nota de los que la tienen (data-testid="pending-prerequisites-notes").
--}}
<div class="space-y-4" data-testid="pending-placements">
    @if ($historical && $total > 0)
        <x-alert icon="o-archive-box" class="alert-info alert-soft text-sm" data-testid="pending-historical">
            <strong>Retícula histórica:</strong> el SII no trae el semestre de estas materias y ningún alumno está inscrito en este plan. Solo hace falta asignárselo si vuelve un alumno.
        </x-alert>
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <aside aria-labelledby="pending-tray-title" data-testid="pending-tray"
            class="flex min-w-0 flex-col rounded-box border border-warning/40 bg-warning/8 lg:sticky lg:top-[calc(var(--shell-top)+1rem)] lg:max-h-[calc(100dvh-var(--shell-top)-2rem)] lg:w-64 lg:shrink-0">
            <h2 id="pending-tray-title" class="flex items-center justify-between gap-2 px-3.5 pt-3 pb-2 font-semibold text-warning">
                Por validar
                <span class="font-mono text-sm">{{ $total }}</span>
            </h2>

            @if ($total === 0)
                <div class="grid justify-items-center gap-2.5 px-3.5 pt-1 pb-5 text-center" data-testid="pending-empty">
                    <span class="grid size-11 place-items-center rounded-full bg-success/15 text-success" aria-hidden="true"><x-icon name="o-check" class="h-5 w-5" /></span>
                    <p class="font-semibold">No hay materias por validar en este plan</p>
                </div>
            @else
                <div class="px-2.5 pb-2">
                    <x-input wire:model.live.debounce.250ms="search" placeholder="Buscar" aria-label="Buscar materias por validar" icon="o-magnifying-glass" class="input-sm" clearable />
                </div>

                <div class="flex min-h-0 gap-1.5 overflow-x-auto px-2.5 pb-2.5 lg:flex-col lg:overflow-x-visible lg:overflow-y-auto" data-testid="pending-chips">
                    @forelse ($pendings as $pending)
                        <button type="button" wire:key="pending-{{ $pending->id }}" wire:click="pick('{{ $pending->id }}')"
                            data-pending="{{ $pending->subject->code }}" aria-pressed="{{ $picked?->id === $pending->id ? 'true' : 'false' }}"
                            @if ($suggests && $pending->suggested_semester !== null && $pending->suggested_semester <= $offeredSemesters) aria-haspopup="dialog" @endif
                            class="relative flex w-52 shrink-0 cursor-pointer flex-col gap-0.5 rounded-field border border-dashed border-warning/60 bg-base-100 px-2.5 py-2 text-left hover:border-warning aria-pressed:border-solid aria-pressed:border-primary aria-pressed:ring-2 aria-pressed:ring-primary/30 lg:w-auto">
                            <span class="text-[13.5px] font-medium leading-tight">{{ $pending->subject->name }}</span>
                            <span class="flex items-baseline justify-between gap-2 font-mono text-xs text-neutral">
                                <span>{{ $pending->subject->code }} · {{ $pending->subject->theory_hours }}-{{ $pending->subject->practice_hours }}-{{ $pending->subject->credits }}</span>
                                @if ($pending->sii_grid)
                                    <span title="Retícula del SII"><span class="sr-only">Retícula del SII</span> {{ $pending->sii_grid }}</span>
                                @endif
                            </span>
                            @if ($suggests && $pending->suggested_semester !== null)
                                <span class="text-xs text-neutral">
                                    Sugerido: <span class="font-mono font-medium text-base-content">{{ $pending->suggested_semester }}</span>
                                    @if ($pending->suggested_semester > $offeredSemesters)
                                        (aún no se ofrece en esta retícula)
                                    @endif
                                </span>
                            @endif
                        </button>
                    @empty
                        <p class="px-1 py-2 text-sm text-neutral">Ninguna coincide con la búsqueda.</p>
                    @endforelse
                </div>
            @endif
        </aside>

        <div class="min-w-0 flex-1">
            @if ($grid['semesters'] === [])
                <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center" data-testid="pending-grid">
                    <p class="text-neutral">Este plan todavía no tiene semestres ni materias en su retícula.</p>
                    @if ($picked !== null)
                        <button type="button" wire:click="openPlace(1)" data-place-semester="1" aria-label="Colocar {{ $picked->subject->name }} en el semestre 1"
                            class="btn btn-sm btn-outline btn-primary mt-3 border-dashed"><x-icon name="o-plus" class="h-4 w-4" />Colocar aquí</button>
                    @endif
                </div>
            @else
                <x-core::semester-grid.frame :layout="$layout" data-testid="pending-grid">
                    @foreach ($layout->bands as $specialtyId => $bandRow)
                        <x-core::semester-grid.band :specialty="$specialtyId" :row="$bandRow" :name="$specialtiesById[$specialtyId]['name'] ?? 'Quitada'" :subjects="$bandSubjects[$specialtyId]" wire:key="pending-band-{{ $specialtyId }}" />
                    @endforeach

                    @foreach ($grid['semesters'] as $semester => $rows)
                        <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" wire:key="pending-semester-{{ $semester }}">
                            <x-slot:figures>{{ $commonCredits[$semester] }} cr</x-slot:figures>
                            @if ($picked !== null && $semester <= $offeredSemesters)
                                <x-slot:below>
                                    <button type="button" wire:click="openPlace({{ $semester }})" data-place-semester="{{ $semester }}" aria-label="Colocar {{ $picked->subject->name }} en el semestre {{ $semester }}"
                                        class="btn btn-sm btn-outline btn-primary mt-2 w-full gap-1 whitespace-nowrap border-dashed px-1 text-[13px] max-md:h-11"><x-icon name="o-plus" class="h-4 w-4 md:hidden" />Colocar aquí</button>
                                </x-slot:below>
                            @endif

                            @foreach ($rows as $row)
                                @if ($row['specialty_id'] !== null && ($loop->first || $rows[$loop->index - 1]['specialty_id'] !== $row['specialty_id']))
                                    <x-core::semester-grid.separator :specialty="$row['specialty_id']" :name="$specialtiesById[$row['specialty_id']]['name'] ?? 'Quitada'" wire:key="pending-separator-{{ $semester }}-{{ $row['specialty_id'] }}" />
                                @endif

                                <x-core::semester-grid.tile
                                    :interactive="false"
                                    :row="$layout->rows[$row['placement_id']]"
                                    :code="$row['code']"
                                    :name="$row['name']"
                                    :muted="! $row['is_active']"
                                    wire:key="pending-tile-{{ $row['placement_id'] }}"
                                    :data-subject="$row['code']"
                                    :data-new="$placedId === $row['placement_id']"
                                    @class([
                                        'data-[new]:ring-2 data-[new]:ring-success data-[new]:ring-offset-2 data-[new]:ring-offset-base-100',
                                        'border-base-300' => $row['is_required'],
                                        'border-dashed border-neutral' => ! $row['is_required'],
                                        'bg-base-100' => $row['is_active'],
                                        'bg-base-200 text-neutral' => ! $row['is_active'],
                                    ])
                                >
                                    @unless ($row['is_active'])
                                        <x-slot:icon><span class="shrink-0" title="Inactiva"><x-icon name="o-minus-circle" class="w-4 h-4" aria-hidden="true" /><span class="sr-only">Inactiva</span></span></x-slot:icon>
                                    @endunless
                                    <x-slot:start><span title="Horas de teoría, de práctica y créditos">{{ $row['theory_hours'] }}-{{ $row['practice_hours'] }}-{{ $row['credits'] }}</span></x-slot:start>
                                    @unless ($row['is_required'])
                                        <x-slot:end><span class="font-sans">Optativa</span></x-slot:end>
                                    @endunless
                                </x-core::semester-grid.tile>
                            @endforeach
                        </x-core::semester-grid.semester>
                    @endforeach
                </x-core::semester-grid.frame>
            @endif
        </div>
    </div>

    {{-- Los atributos que no son de x-drawer caen en su panel: ahí van la semántica de diálogo y el título que lo nombra. --}}
    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="pending-drawer-title" data-testid="pending-drawer">
        @if ($picked !== null && $showDrawer)
            <form wire:submit="place" class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Agregar a la retícula</p>
                        <h2 id="pending-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $picked->subject->name }}</h2>
                        <p class="font-mono text-neutral">{{ $picked->subject->code }}</p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
                </header>

                <div class="flex-1 space-y-5 px-5 pt-4 pb-8">
                    @error('pending')
                        <x-alert :title="$message" icon="o-x-circle" class="alert-error alert-soft text-sm" data-testid="pending-error" />
                    @enderror

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3" data-testid="pending-facts">
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Créditos</dt>
                            <dd class="font-mono text-[15px] font-medium">{{ $picked->subject->credits }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horas T-P</dt>
                            <dd class="font-mono text-[15px] font-medium">{{ $picked->subject->theory_hours }}-{{ $picked->subject->practice_hours }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Especialidad</dt>
                            <dd class="text-[15px] font-medium">{{ $picked->specialty?->name ?? 'Ninguna' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Retícula SII</dt>
                            <dd class="font-mono text-[15px] font-medium">{{ $picked->sii_grid ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if ($prerequisites['enters'] !== [])
                        <p class="text-sm leading-snug" data-testid="pending-prerequisites">
                            Al asignarle semestre entran a la seriación:
                            @foreach ($prerequisites['enters'] as $item)
                                <span wire:key="enters-{{ $item['id'] }}">{{ $item['requires'] ? 'pide ' : '' }}<span class="font-mono">{{ $item['code'] }}</span>{{ $item['requires'] ? '' : ' la pide' }} ({{ mb_strtolower($item['kind']->label()) }}@if ($item['cycle'])<span class="text-warning">; no entraría: cerraría un ciclo de seriación</span>@endif){{ $loop->last ? '.' : ',' }}</span>
                            @endforeach
                        </p>
                    @endif

                    @if ($prerequisites['waits'] !== [])
                        <p class="text-sm leading-snug text-neutral" data-testid="pending-prerequisites-waiting">
                            Esperan a que se valide la otra materia:
                            @foreach ($prerequisites['waits'] as $item)
                                <span wire:key="waits-{{ $item['id'] }}">{{ $item['requires'] ? 'pide ' : '' }}<span class="font-mono">{{ $item['code'] }}</span>{{ $item['requires'] ? '' : ' la pide' }} ({{ mb_strtolower($item['kind']->label()) }}){{ $loop->last ? '.' : ',' }}</span>
                            @endforeach
                        </p>
                    @endif

                    @if ($prerequisites['notes'] !== [])
                        <ul class="space-y-0.5 text-sm leading-snug text-warning" data-testid="pending-prerequisites-notes">
                            @foreach ($prerequisites['notes'] as $item)
                                <li wire:key="note-{{ $item['id'] }}"><span class="font-mono">{{ $item['code'] }}</span>: {{ $item['note'] }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <x-select label="Semestre" name="semester" wire:model.live="semester" :options="$semesterOptions" data-testid="pending-semester"
                        :hint="! $suggests || $picked->suggested_semester === null ? null : 'Sugerido: '.$picked->suggested_semester.($picked->suggested_semester > $offeredSemesters ? ' (aún no se ofrece en esta retícula)' : '')" />

                    @if ($load !== null)
                        <p class="rounded-field bg-base-200 px-3 py-2.5 text-neutral" data-testid="pending-load" aria-live="polite">
                            Semestre {{ $load['semester'] }}:
                            <span class="font-medium text-base-content">{{ $load['subjects'] }} {{ $load['subjects'] === 1 ? 'materia' : 'materias' }} · {{ $load['credits'] }} cr</span>
                            →
                            <span class="font-mono font-medium text-base-content">{{ $load['after'] }} cr</span>
                        </p>
                    @endif
                </div>

                <footer class="sticky bottom-0 flex justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
                    <x-button label="Cancelar" @click="close()" />
                    <x-button label="Agregar a la retícula" type="submit" icon="o-check" class="btn-primary" spinner="place" data-testid="pending-submit" />
                </footer>
            </form>
        @endif
    </x-drawer>
</div>
