{{--
    Ficha del alumno (DESIGN.md, "Retícula del alumno"): encabezado con sus datos y cifras, y dos pestañas. La
    retícula va en columnas por semestre, como la del SII, y en celular los semestres se apilan. Cada materia
    lleva el relleno, el borde y el icono de su estado, y el estado en texto para lectores de pantalla.
--}}
@use('App\Modules\Students\Enums\SubjectStanding')
<div class="space-y-5">
    <a href="{{ route('students.index') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="size-4" />Alumnos
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
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-neutral">
            @foreach ($standings as $standing)
                <span class="inline-flex items-center gap-1.5">
                    <span @class(['inline-grid size-5 place-items-center rounded border', $standing->tileClasses()])><x-icon :name="$standing->icon()" class="size-3.5" /></span>
                    {{ $standing->label() }}
                </span>
            @endforeach
        </div>
        @if ($record->reachSemester !== null)
            <p class="text-neutral">Puede seleccionar materias hasta el semestre <span class="font-mono">{{ $record->reachSemester }}</span>, el suyo y dos más.</p>
        @endif

        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100 max-md:overflow-visible max-md:border-0 max-md:bg-transparent">
            <div class="flex min-w-max max-md:min-w-0 max-md:flex-col max-md:gap-3 md:min-w-full" data-testid="student-grid">
                @foreach ($record->semesters as $semester => $cells)
                    <section @class([
                        'flex min-w-28 flex-1 basis-0 flex-col gap-2 border-base-300 px-1.5 pt-2.5 pb-3 md:[&:not(:first-child)]:border-l',
                        'max-md:grid max-md:grid-cols-2 max-md:rounded-box max-md:border max-md:bg-base-100 max-md:px-2',
                        'bg-base-200/60' => $semester === $record->student->current_semester,
                    ]) aria-label="Semestre {{ $semester }}">
                        <h3 class="px-0.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:col-span-2">
                            Semestre {{ $semester }}
                            <span class="block font-mono text-xs normal-case tracking-normal">{{ $record->approvedBySemester[$semester] }} de {{ count($cells) }}</span>
                            @if ($semester === $record->student->current_semester)
                                <span class="block text-xs font-semibold normal-case tracking-normal text-base-content">Su semestre</span>
                            @elseif ($record->reachSemester !== null && $semester > $record->reachSemester)
                                <span class="block text-xs normal-case tracking-normal">Fuera de su avance</span>
                            @endif
                        </h3>
                        @foreach ($cells as $cell)
                            <div @class(['flex min-h-24 flex-col gap-1 rounded-field border px-2.5 py-2', $cell->standing->tileClasses()]) title="{{ $cell->standing->label() }}" data-subject="{{ $cell->code }}">
                                <span class="flex items-center justify-between gap-1.5">
                                    <span class="whitespace-nowrap font-mono text-[12.5px] font-semibold">{{ $cell->code }}</span>
                                    <x-icon :name="$cell->standing->icon()" class="size-4 shrink-0" aria-hidden="true" />
                                </span>
                                <span class="line-clamp-3 text-[13px] leading-snug text-base-content">{{ $cell->name }}</span>
                                <span class="sr-only">{{ $cell->standing->label() }}</span>
                                @if ($cell->specialty)
                                    <span class="text-[11.5px] text-neutral">Esp. {{ $cell->specialty }}</span>
                                @endif
                                @if ($cell->standing === SubjectStanding::NoPermitida)
                                    <span class="whitespace-nowrap text-[11.5px] text-neutral">
                                        @if ($cell->missing !== [])
                                            Pide <span class="font-mono font-semibold">{{ implode(', ', $cell->missing) }}</span>
                                        @elseif ($cell->beyondReach)
                                            Fuera de su avance
                                        @elseif ($cell->creditShare !== null)
                                            Pide {{ $cell->creditShare }} % de los créditos
                                        @endif
                                    </span>
                                @endif
                                <span class="mt-auto flex items-center justify-between gap-1.5 whitespace-nowrap text-xs">
                                    <span class="font-mono">{{ $cell->credits }} cr</span>
                                    <span class="flex gap-1.5 font-mono">
                                        @if ($cell->attempt !== null && $cell->attempt > 1)<span>{{ $cell->attempt }}.ª</span>@endif
                                        @if ($cell->grade !== null)<span>{{ $cell->grade }}</span>@endif
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>
        </div>
    @else
        <p class="text-neutral">Cada materia que ha cursado, por periodo, como viene en la última importación de kárdex.</p>
        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="student-kardex">
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
