{{--
    Ficha del alumno (DESIGN.md, "Retícula del alumno"): encabezado con sus datos y cifras, y dos pestañas. La
    retícula va en columnas por semestre, como la del SII, con las piezas de Core (core::semester-grid): una sola
    cuadrícula con mosaicos de tamaño fijo, el tronco común arriba y una banda por especialidad; en celular los
    semestres se apilan. Cada materia lleva el relleno, el borde y el icono de su estado, y el estado en texto para
    lectores de pantalla. Arriba va la leyenda de estados; debajo, la de cadenas y la barra de selección.
--}}
@use('App\Modules\Students\Enums\ChainColor')
@use('App\Modules\Students\Enums\SubjectStanding')
<div class="space-y-5">
    <a href="{{ route('students.index') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="w-4 h-4" />Alumnos
    </a>

    <div>
        <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
            {{ $record->student->name }}
            <span class="font-mono text-xl font-semibold text-neutral tabular-nums">{{ $record->student->control_number }}</span>
            @include('students::status-badge', ['status' => $record->student->status])
        </h1>
        <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
            <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $record->student->career->code }}</span> · {{ $record->student->career->name }}</span></span>
            <span>Plan <span class="font-mono font-medium text-base-content">{{ $record->student->studyPlan->code }}</span></span>
            <span>Campus <span class="font-medium text-base-content">{{ $record->student->campus->name }}</span></span>
            <span>Semestre <span class="font-mono font-medium text-base-content">{{ $record->student->current_semester ?? '—' }}</span></span>
        </p>
    </div>

    <div class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 desktop:grid-cols-4">
        @foreach ([
            ['Créditos aprobados', $record->creditsApproved, $record->totalCredits],
            ['Materias acreditadas', $record->subjectsApproved, $record->subjectsInGrid],
            ['Cursando', $record->taking, null],
            ['Puede seleccionar', $record->selectable, null],
        ] as [$label, $value, $of])
            <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 [&:nth-child(n+2)]:border-l max-desktop:[&:nth-child(3)]:border-l-0 max-desktop:[&:nth-child(n+3)]:border-t">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                <span class="font-mono text-2xl font-semibold tabular-nums">{{ $value }}@if ($of !== null) <span class="text-base font-normal text-neutral">de {{ $of }}</span>@endif</span>
                @if ($loop->first && $of > 0)
                    <progress class="progress progress-success h-1.5" value="{{ $value }}" max="{{ $of }}" aria-label="Avance en créditos"></progress>
                @endif
            </div>
        @endforeach
    </div>

    <div role="tablist" class="tabs tabs-border">
        <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === 'reticula']) aria-selected="{{ $tab === 'reticula' ? 'true' : 'false' }}" wire:click="$set('tab', 'reticula')">Retícula</button>
        <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === 'kardex']) aria-selected="{{ $tab === 'kardex' ? 'true' : 'false' }}" wire:click="$set('tab', 'kardex')" data-testid="tab-kardex">Kárdex</button>
    </div>

    @if ($tab !== 'kardex')
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
        <div x-data="studentGrid" wire:ignore wire:key="student-grid" @keydown.escape.window="clearUnlessLayerOpen()" class="space-y-4">
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
                                :class="$cell->standing->tileClasses()" :title="$cell->standing->label()" aria-pressed="false" x-on:click="toggleSubject($el)"
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

            @if ($record->chains !== [])
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

            <x-core::semester-grid.selection-bar x-ref="bar">
                <x-slot:info x-ref="info"></x-slot:info>
                <x-slot:clear x-on:click="select(null)"></x-slot:clear>
            </x-core::semester-grid.selection-bar>
        </div>
    @else
        <p class="text-neutral">Cada materia que ha cursado, por periodo y desde el primero, como viene en la última importación de kárdex.</p>
        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="student-kardex" wire:key="student-kardex">
            @forelse ($record->kardex as $term)
                <div class="flex flex-wrap items-center justify-between gap-2 border-base-300 bg-base-200/60 px-5 py-2.5 font-bold [&:not(:first-child)]:border-t">
                    <span><span class="font-mono">{{ $term['term'] }}</span> · {{ $term['label'] }}</span>
                    <span class="text-sm font-normal text-neutral"><span class="font-mono">{{ count($term['records']) }}</span> materias</span>
                </div>
                @foreach ($term['records'] as $row)
                    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-t border-base-300 px-5 py-2.5 md:grid-cols-[6.5rem_minmax(0,1fr)_4rem_9rem_9rem]">
                        <span class="font-mono font-semibold">{{ $row->subject->code }}</span>
                        <span>{{ $row->subject->name }}</span>
                        <span class="text-right font-mono tabular-nums max-md:hidden">{{ $row->grade_label }}</span>
                        <x-core::status-badge :tone="$row->result->tone()" :label="$row->result->label()" />
                        <span class="text-neutral max-md:col-span-3">{{ $row->attempt_label }}</span>
                    </div>
                @endforeach
            @empty
                <p class="px-5 py-6 text-center text-neutral">El kárdex de este alumno todavía no se importa.</p>
            @endforelse
        </div>
    @endif
</div>
