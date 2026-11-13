{{--
    La vista «Retícula» de la propuesta (maqueta C «Las dos», elegida por el usuario el 4 de noviembre de 2026; DESIGN.md,
    "Retícula de la propuesta de carrera"; mismas piezas y medidas que "Retícula del alumno"): arriba, la leyenda de
    los estados; semestres en columnas con los mosaicos de Core (core::semester-grid), uno por materia, y las bandas
    de especialidad del plan; debajo, la leyenda de las cadenas de seriación y la barra de selección.

    Cada mosaico lleva la franja de su cadena (`$chains['of']`, la misma franja de la retícula del alumno) y el tinte
    de su estado (ProposalTileState, en `data-tone`): regresada en error, aceptada en éxito con candado (o palomita si
    toda la propuesta está bloqueada, `$generalLock`, en el mosaico y en la leyenda), sin grupos en advertencia
    punteada y con grupos neutra. Dice, de
    arriba abajo, su clave y el icono de su estado, el nombre, los alumnos posibles y, al pie, los grupos en Mono, el
    código del departamento solo si no es el mayoritario de la carrera (`$majority`) y el conteo de anomalías. Con una
    sola columna, «Pos. N · Esp. N» y «Grupos N»; con varias (M6, maqueta U1 opción A), «Pos. 174 · 36» (los posibles de
    cada campus, en el orden de las columnas) y al pie la etiqueta de cada campus con sus grupos. Un mosaico es una
    materia con todas sus filas: abrirlo lleva al panel con cada campus.

    Lo marcado (`$highlight`) se pinta como una cadena resaltada en la retícula del alumno: `data-focus` en la
    cuadrícula, `data-on-chain` en lo marcado y lo demás muy atenuado. Es el departamento elegido, el filtro de grupos
    o los dos; o, mientras está, la cadena que se tocó en la leyenda. La barra dice qué está marcado y «Quitar
    resaltado» (o Esc, si no hay un panel, un diálogo ni el menú encima) lo quita: primero la cadena, luego lo demás.
    El encabezado de cada semestre cuenta lo marcado (`$figures`). Lo pinta el servidor, como en el editor del plan.
--}}
@if ($layout->columns === [])
    <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">Esta propuesta no tiene materias.</div>
@else
    <div class="space-y-4"
        x-on:keydown.escape.window="if (($wire.chain !== null || $wire.department !== '' || $wire.groupsFilter !== '') && ! $wire.showDrawer && ! $wire.showSubmit && document.body.dataset.menu !== 'open' && ! document.querySelector('dialog[open]')) { $wire.clearHighlight() }">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-neutral" data-testid="state-legend">
            @foreach ($tileStates as $state)
                @php
                    $icon = $state->icon($generalLock !== null);
                @endphp
                <span class="inline-flex items-center gap-1">
                    <span @class(['inline-flex w-6 h-6 shrink-0 items-center justify-center rounded border', $state->tileClasses()]) aria-hidden="true" data-testid="state-{{ $state->value }}" data-icon="{{ $icon }}">
                        @if ($icon)
                            <x-icon :name="$icon" class="w-4 h-4" />
                        @endif
                    </span>
                    {{ $state->label() }}
                </span>
            @endforeach
        </div>

        <x-core::semester-grid.frame :layout="$layout" data-testid="proposal-grid" :data-focus="$highlight['marked'] !== null ? '' : null">
            @foreach ($overview->specialties as $specialty)
                <x-core::semester-grid.band :specialty="$specialty['id']" :row="$layout->bands[$specialty['id']]" :name="$specialty['name']" :subjects="$specialty['subjects']" />
            @endforeach

            @foreach ($gridSemesters as $semester => $items)
                <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" :note="$figures[$semester]['note']" wire:key="semester-{{ $semester }}">
                    <x-slot:figures><span data-figure="{{ $semester }}">{{ $figures[$semester]['text'] }}</span></x-slot:figures>

                    @foreach ($items as $row)
                        @php
                            $code = $row->subject->department->code;
                            $state = \App\Modules\AcademicLoad\Enums\ProposalTileState::of($parts[$row->subject->department_id], $row->groups());
                            $icon = $state->icon($generalLock !== null);
                            $stateText = match ($state) {
                                \App\Modules\AcademicLoad\Enums\ProposalTileState::Returned => 'Regresada por '.$code,
                                \App\Modules\AcademicLoad\Enums\ProposalTileState::Accepted => 'Aceptada por '.$code.($generalLock === null ? ': ya no cambia' : ''),
                                default => 'Sin grupos',
                            };
                            $anomalies = $overview->subjectAnomalies($row);
                            $specialty = $overview->specialtyFor($row);
                            $classes = Illuminate\Support\Arr::toCssClasses([
                                $state->tileClasses(),
                                'data-[found]:ring-2 data-[found]:ring-error data-[found]:ring-offset-2 data-[found]:ring-offset-base-100',
                            ]);
                            $firstLock = collect($row->items())->map(fn ($item) => $locks[$item->id] ?? null)->filter()->first();
                            $title = implode(' · ', array_filter([$firstLock?->message($code), ...$anomalies]));
                            $multi = count($columns) > 1;
                            $eligibleText = collect($columns)->map(fn ($column, $key) => ($row->cells[$key] ?? null)?->eligible_students ?? '—')->implode(' · ');
                        @endphp
                        @if ($specialty !== null && ($loop->first || $overview->specialtyFor($items[$loop->index - 1]) !== $specialty))
                            <x-core::semester-grid.separator :specialty="$specialty" :name="$specialtyNames[$specialty]" wire:key="separator-{{ $semester }}-{{ $specialty }}" />
                        @endif

                        <x-core::semester-grid.tile :row="$layout->rows[$row->subject->code]" :code="$row->subject->code" :name="$row->subject->name" :chain="$chains['of'][$row->id()] ?? null"
                            :class="$classes"
                            wire:click="edit('{{ $row->id() }}')" wire:key="tile-{{ $row->id() }}" :data-item="$row->id()" :data-me="$subjectId === $row->id() ? '' : null"
                            :data-on-chain="isset($highlight['marked'][$row->id()]) ? '' : null"
                            :title="$title !== '' ? $title : null" data-opener data-testid="tile-{{ $row->subject->code }}" data-part="{{ $parts[$row->subject->department_id] }}" data-tone="{{ $state->value }}">
                            <x-slot:icon>
                                @if ($icon)
                                    @if ($icon === 'o-lock-closed')
                                        <x-icon :name="$icon" class="w-4 h-4 shrink-0" aria-hidden="true" data-lock />
                                    @else
                                        <x-icon :name="$icon" class="w-4 h-4 shrink-0" aria-hidden="true" />
                                    @endif
                                    <span class="sr-only">{{ $stateText }}</span>
                                @endif
                            </x-slot:icon>
                            @if ($multi)
                                <x-slot:requisites class="flex items-baseline" data-testid="figures-{{ $row->subject->code }}" title="Alumnos posibles por campus: {{ collect($columns)->map(fn ($column, $key) => ($column['campus']?->code ?? '—').' '.(($row->cells[$key] ?? null)?->eligible_students ?? 0))->implode(' · ') }}"><span class="truncate">Pos. <span class="font-mono">{{ $eligibleText }}</span></span></x-slot:requisites>
                                <x-slot:start><span class="flex items-center gap-1.5" data-testid="groups-{{ $row->subject->code }}">
                                    @foreach ($columns as $key => $column)
                                        <span @class(['inline-flex items-center gap-0.5', 'text-neutral' => (($row->cells[$key] ?? null)?->requested_groups ?? 0) === 0])><x-academic-load::campus-tag :campus="$column['campus']" size="xs" /><span class="text-[13px] font-semibold">{{ ($row->cells[$key] ?? null)?->requested_groups ?? '—' }}</span></span>
                                    @endforeach
                                </span></x-slot:start>
                            @else
                                <x-slot:requisites class="flex items-baseline" data-testid="figures-{{ $row->subject->code }}" title="Alumnos posibles {{ $row->eligible() }} · Esperados {{ $row->expected() }}"><span class="truncate">Pos. <span class="font-mono">{{ $row->eligible() }}</span> · <span class="min-[1600px]:hidden">Es.</span><span class="max-[1599px]:hidden">Esp.</span> <span class="font-mono">{{ $row->expected() }}</span></span></x-slot:requisites>
                                <x-slot:start><span data-testid="groups-{{ $row->subject->code }}"><span class="font-sans text-xs font-normal">Grupos</span> <span class="text-[15px] font-semibold">{{ $row->groups() }}</span></span></x-slot:start>
                            @endif
                            <x-slot:end>
                                @if ($code !== $majority)
                                    <span class="font-normal" data-testid="dept-{{ $row->subject->code }}" title="{{ $row->subject->department->name }}">{{ $code }}</span>
                                @endif
                                @if ($anomalies !== [])
                                    <span class="inline-flex items-center gap-0.5 font-semibold text-warning" data-testid="anomaly-{{ $row->subject->code }}">
                                        <x-icon name="o-exclamation-triangle" class="w-4 h-4" aria-hidden="true" />
                                        <span data-anomaly-count>{{ count($anomalies) }}</span>
                                        <span class="sr-only">Revisar: {{ implode('; ', $anomalies) }}</span>
                                    </span>
                                @endif
                            </x-slot:end>
                        </x-core::semester-grid.tile>
                    @endforeach
                </x-core::semester-grid.semester>
            @endforeach
        </x-core::semester-grid.frame>

        @if ($chains['chains'] !== [])
            <div x-data="{ open: false }" data-testid="chain-legend">
                {{-- En celular (menos de 768 px) la leyenda va plegada tras este botón; desde md siempre está abierta. --}}
                <button type="button" data-testid="chain-legend-toggle" class="btn btn-sm btn-ghost md:hidden" aria-controls="proposal-chain-chips" x-bind:aria-expanded="open.toString()" aria-expanded="false" @click="open = ! open">
                    Cadenas de seriación ({{ count($chains['chains']) }})
                </button>
                <div id="proposal-chain-chips" class="flex flex-wrap items-center gap-1.5" x-bind:class="open ? '' : 'max-md:hidden'">
                    <span class="mr-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden">Cadenas de seriación</span>
                    @foreach ($chains['chains'] as $link)
                        <button type="button" wire:click="focusChain({{ $link['index'] }})" wire:key="chain-{{ $link['index'] }}" data-chain="{{ $link['index'] }}" aria-pressed="{{ $chain === $link['index'] ? 'true' : 'false' }}" data-testid="chain-{{ $link['index'] }}"
                            class="inline-flex h-7 items-center gap-1.5 rounded-field border border-base-300 bg-base-100 px-2 text-[12.5px] aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary">
                            <span @class(['size-3.5 rounded-sm border-[3px]', \App\Modules\Students\Enums\ChainColor::forChain($link['index'])->swatchClasses()]) aria-hidden="true"></span>
                            {{ $link['name'] }}
                            <span class="font-mono text-neutral">{{ $link['size'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <x-core::semester-grid.selection-bar :shown="$highlight['info'] !== ''">
            <x-slot:info>{{ $highlight['info'] }}</x-slot:info>
            <x-slot:clear wire:click="clearHighlight" data-testid="clear-highlight"></x-slot:clear>
        </x-core::semester-grid.selection-bar>
    </div>
@endif
