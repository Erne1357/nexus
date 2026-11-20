{{--
    Ficha del alumno (DESIGN.md, "Retícula del alumno"): encabezado con sus datos y cifras, y dos pestañas. La
    retícula va en columnas por semestre, como la del SII, con las piezas de Core (core::semester-grid): una sola
    cuadrícula con mosaicos de tamaño fijo, el tronco común arriba y una banda por especialidad; en celular los
    semestres se apilan. Cada materia lleva el relleno, el borde y el icono de su estado, y el estado en texto para
    lectores de pantalla. Arriba va la leyenda de estados; debajo, la de cadenas y la barra de selección.

    Debajo de la retícula, las materias de su plan que esperan semestre (D18), en el orden del SII: clave, nombre,
    créditos y si las acreditó, bajo «Por validar» o, en una retícula histórica, «Sin semestre en el SII». Un plan sin
    ningún semestre no pinta la cuadrícula ni su leyenda: solo esa lista.
--}}
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
        <x-students::reticula :record="$record" />
    @else
        <x-students::kardex :record="$record" />
    @endif
</div>
