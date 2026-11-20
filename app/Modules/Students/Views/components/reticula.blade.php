{{--
    La retícula del alumno por semestre (DESIGN.md, "Retícula del alumno"), la misma en la ficha del personal
    (students::pages.student) y en el «Perfil» del propio alumno (students::pages.my-record). Va en columnas por semestre
    con las piezas de Core (core::semester-grid): el tronco común arriba y una banda por especialidad, con la leyenda de
    estados y, debajo, las materias que esperan semestre (D18). `$record` es el StudentRecord. Con `:interactive="false"`
    es solo lectura, como la ve el alumno: mosaicos sin resaltado, sin la leyenda de cadenas y sin la barra de selección.
--}}
@props(['record', 'interactive' => true])
@use('App\Modules\Students\Enums\ChainColor')
@use('App\Modules\Students\Enums\SubjectStanding')
@php
    $layout = $record->gridLayout();
    $specialtyNames = $record->specialtyNames();
    $standings = SubjectStanding::cases();
@endphp
@if ($record->semesters === [] && $record->unplaced === [])
    <p class="text-neutral" data-testid="student-grid-empty">Su plan todavía no tiene materias en la retícula.</p>
@else
@if ($record->semesters !== [])
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-neutral" data-testid="standing-legend">
        @foreach ($standings as $standing)
            <span class="inline-flex items-center gap-1">
                <span @class(['inline-flex w-6 h-6 shrink-0 items-center justify-center rounded border', $standing->tileClasses()]) aria-hidden="true"><x-icon :name="$standing->icon()" class="w-4 h-4" /></span>
                {{ $standing->label() }}
            </span>
        @endforeach
    </div>

    {{--
        wire:ignore: el resaltado vive en Alpine, y volver a pintar la página no debe borrarlo. Su wire:key y el
        del kárdex hacen que, al cambiar de pestaña, Livewire lo cambie por el kárdex en lugar de saltárselo.
    --}}
    <div @if ($interactive) x-data="studentGrid" wire:ignore @keydown.escape.window="clearUnlessLayerOpen()" @endif wire:key="student-grid" class="space-y-4">
        <x-core::semester-grid.frame :layout="$layout" :current="$record->student->current_semester" data-testid="student-grid" x-ref="grid">
            @foreach ($record->specialties as $specialty)
                <x-core::semester-grid.band :specialty="$specialty['id']" :row="$layout->bands[$specialty['id']]" :name="$specialty['name']" :subjects="$specialty['subjects']" />
            @endforeach

            @foreach ($record->semesters as $semester => $cells)
                @php($isCurrent = $semester === $record->student->current_semester)
                <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" :current="$isCurrent" :note="$isCurrent ? 'su semestre' : null">
                    <x-slot:figures>{{ $record->approvedBySemester[$semester] }} de {{ count($cells) }}</x-slot:figures>

                    @foreach ($cells as $cell)
                        @if ($cell->specialtyId !== null && ($loop->first || $cells[$loop->index - 1]->specialtyId !== $cell->specialtyId))
                            <x-core::semester-grid.separator :specialty="$cell->specialtyId" :name="$specialtyNames[$cell->specialtyId]" />
                        @endif

                        <x-core::semester-grid.tile :row="$layout->rows[$cell->code]" :chain="$cell->chain" :code="$cell->code" :name="$cell->name"
                            :class="$cell->standing->tileClasses()" :title="$cell->standing->label()" :interactive="$interactive" :aria-pressed="$interactive ? 'false' : null" :x-on:click="$interactive ? 'toggleSubject($el)' : null"
                            :data-subject="$cell->code" :data-name="$cell->name" :data-prereqs="implode(' ', $cell->prerequisites)" :data-chain="$cell->chain">
                            <x-slot:icon><x-icon :name="$cell->standing->icon()" class="w-4 h-4 shrink-0" aria-hidden="true" /></x-slot:icon>
                            <span class="sr-only">{{ $cell->standing->label() }}</span>

                            @if ($cell->standing === SubjectStanding::NoPermitida)
                                @if ($cell->missing !== [])
                                    <x-slot:requisites :title="'Pide '.implode(', ', $cell->missing)">Pide <span class="font-mono font-semibold">{{ implode(', ', $cell->missing) }}</span></x-slot:requisites>
                                @elseif ($cell->beyondReach)
                                    <x-slot:requisites title="Fuera de su avance">Fuera de su avance</x-slot:requisites>
                                @elseif ($cell->creditShare !== null)
                                    <x-slot:requisites :title="'Pide '.$cell->creditShare.' % de los créditos'">Pide {{ $cell->creditShare }} % de los créditos</x-slot:requisites>
                                @endif
                            @endif

                            <x-slot:start>{{ $cell->credits }} cr</x-slot:start>
                            <x-slot:end>
                                @if ($cell->attempt !== null && $cell->attempt > 1)<span>{{ $cell->attempt }}.ª</span>@endif
                                @if ($cell->grade !== null)<span>{{ $cell->grade }}</span>@endif
                            </x-slot:end>
                        </x-core::semester-grid.tile>
                    @endforeach
                </x-core::semester-grid.semester>
            @endforeach
        </x-core::semester-grid.frame>

        @if ($interactive && $record->chains !== [])
            <div x-data="{ open: false }" data-testid="chain-legend">
                {{-- En celular (menos de 768 px) la leyenda va plegada tras este botón; desde md siempre está abierta. --}}
                <button type="button" data-testid="chain-legend-toggle" class="btn btn-sm btn-ghost md:hidden" aria-controls="student-chain-chips" x-bind:aria-expanded="open.toString()" aria-expanded="false" @click="open = ! open">
                    Cadenas de seriación ({{ count($record->chains) }})
                </button>
                <div id="student-chain-chips" class="flex flex-wrap items-center gap-1.5" x-bind:class="open ? '' : 'max-md:hidden'">
                    <span class="mr-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden">Cadenas de seriación</span>
                    @foreach ($record->chains as $chain)
                        <button type="button" data-chain-chip data-chain="{{ $chain['index'] }}" data-name="{{ $chain['name'] }}" aria-pressed="false" @click="toggleChain('{{ $chain['index'] }}')"
                            class="inline-flex h-7 items-center gap-1.5 rounded-field border border-base-300 bg-base-100 px-2 text-[12.5px] aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary">
                            <span @class(['size-3.5 rounded-sm border-[3px]', ChainColor::forChain($chain['index'])->swatchClasses()]) aria-hidden="true"></span>
                            {{ $chain['name'] }}
                            <span class="font-mono text-neutral">{{ $chain['size'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($interactive)
<x-core::semester-grid.selection-bar x-ref="bar">
            <x-slot:info x-ref="info"></x-slot:info>
            <x-slot:clear x-on:click="select(null)"></x-slot:clear>
        </x-core::semester-grid.selection-bar>
@endif
    </div>
@endif

@if ($record->unplaced !== [])
    <section aria-labelledby="student-unplaced-title" class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="student-unplaced" wire:key="student-unplaced">
        <h2 id="student-unplaced-title" class="bg-base-200/60 px-5 py-2.5 font-bold">{{ $record->student->studyPlan->is_historical ? 'Sin semestre en el SII' : 'Por validar' }}</h2>
        @foreach ($record->unplaced as $subject)
            <div class="grid grid-cols-[6.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-t border-base-300 px-5 py-2.5 md:grid-cols-[6.5rem_minmax(0,1fr)_4rem_9rem]" wire:key="student-unplaced-{{ $subject['code'] }}">
                <span class="font-mono font-semibold">{{ $subject['code'] }}</span>
                <span>{{ $subject['name'] }}</span>
                <span class="text-right font-mono tabular-nums">{{ $subject['credits'] }} cr</span>
                <span class="max-md:col-span-3">
                    @if ($subject['accredited'])
                        <x-core::status-badge tone="success" :label="SubjectStanding::Acreditada->label()" />
                    @else
                        <span class="text-neutral">Sin acreditar</span>
                    @endif
                </span>
            </div>
        @endforeach
    </section>
@endif
@endif
