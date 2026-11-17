{{--
    «Paquetes de primer semestre» (tarea 5.6b; opción C, el mismo bloque en las tres maquetas de
    `.impeccable/mocks/decision/demanda-2026-11/`; DESIGN.md, "Paquetes de primer semestre"), arriba de las materias en la
    tabla y en la retícula. Todo sale de ProposalPackagesQuery (`$packages`).

    - Una columna por campus de la propuesta, lado a lado desde 52 rem de caja y apiladas debajo: su nuevo ingreso (un campo
      que guarda al salir si el usuario captura esa columna, M7; si no, el número), lo de Nexus y de dónde sale («Nexus 171 ·
      promedio de los últimos 4 Ene-Jun», o «Corregido · Nexus 171»), los paquetes que sugiere Nexus («paquetes de 35»), cuántos
      abre de todos («Abres» en la suya) y si alcanzan, con icono y texto. La de otro campus va en `secondary/40` con «Solo
      lectura», candado y quién la captura para lectores de pantalla.
    - Sin paquetes, el aviso de la maqueta y, para quien arma la composición, «Armar paquete con las materias de 1.º».
    - Con paquetes, uno por renglón: su nombre y cuántas materias, sus materias como fichas (las de otro semestre con borde
      punteado y su semestre), la cuenta de cada columna (campo en la suya, texto en las demás) y el botón que abre su panel
      («Editar» o, de solo lectura, «Ver»). Desde 48 rem de caja es una tabla con el encabezado de columnas; debajo, cada
      paquete es una ficha con la cuenta de cada campus en una caja con su etiqueta.

    Enviada o aceptada no hay campos ni «Agregar paquete». Una cifra guardada lo dice con la palomita de la tabla
    (`cell-saved`, `data-cell`) y una rechazada, con su error debajo (`$cellErrors`).
--}}
@php
    $open = $proposal->status->isEditable();
    $count = count($columns);
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $figure = 'font-mono text-[22px] font-semibold leading-tight tabular-nums';
    $rowTemplate = '10rem minmax(0,1fr) repeat('.$count.', 5.5rem) 2.5rem';
    $statusTone = ['warning' => ['o-exclamation-triangle', 'text-warning'], 'success' => ['o-check-circle', 'text-success'], 'neutral' => [null, 'text-neutral']];
    $termType = $projection->termType();
    $historyTerms = $projection->settings->historyTerms;
@endphp
<section @class(['@container overflow-hidden rounded-box border border-base-300 bg-base-100', 'max-w-6xl' => $view === 'tabla' && $count <= 3]) aria-labelledby="packages-title" data-testid="packages">
    <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3 px-4 py-3.5 @min-[40rem]:px-5">
        <div class="min-w-0">
            <h2 id="packages-title" class="text-base font-bold leading-snug">Paquetes de primer semestre</h2>
            <p class="mt-0.5 text-sm text-neutral">Cada paquete abre un grupo de cada una de sus materias.</p>
        </div>
        @if ($packagesManageable)
            <x-button label="Agregar paquete" icon="o-plus" class="btn-sm max-md:min-h-11" wire:click="newPackage" spinner="newPackage" data-opener data-testid="packages-add" />
        @endif
    </div>

    <div class="grid border-t border-base-300 @min-[52rem]:[grid-template-columns:var(--package-columns)]" style="--package-columns: repeat({{ $count }}, minmax(0, 1fr))">
        @foreach ($columns as $key => $column)
            @php
                $figures = $packages->columns[$key];
                $intake = $figures['intake'];
                $mine = $column['editable'] && $open;
                $campusCode = $column['campus']?->code ?? 'unica';
                $where = $column['campus']?->code;
                $intakeCell = "intake.{$key}";
                $intakeError = $cellErrors[$intakeCell] ?? null;
                $status = $figures['status'];
            @endphp
            <div role="group" aria-labelledby="packages-campus-{{ $campusCode }}"
                @class([
                    '@container/column min-w-0 border-base-300 px-4 py-3 @min-[40rem]:px-5 [&+&]:border-t @min-[52rem]:[&+&]:border-t-0 @min-[52rem]:[&+&]:border-s',
                    'bg-secondary/40' => ! $column['editable'],
                ])
                wire:key="packages-column-{{ $campusCode }}-{{ $rejections }}-{{ $intake?->new_students }}" data-testid="packages-column-{{ $campusCode }}">
                <h3 id="packages-campus-{{ $campusCode }}" class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-semibold">
                    <x-academic-load::campus-tag :campus="$column['campus']" />
                    <span>{{ $column['campus']?->name ?? 'Alumnos de la modalidad' }}</span>
                    @if (! $column['editable'])
                        <span class="inline-flex items-center gap-0.5 text-xs font-normal text-neutral" title="{{ $column['readonly'] }}"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Solo lectura<span class="sr-only">: {{ $column['readonly'] }}</span></span>
                    @endif
                </h3>

                <div class="grid grid-cols-2 gap-x-5 gap-y-3 @min-[30rem]/column:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.2fr)]">
                    <div class="col-span-2 flex min-w-0 flex-col gap-1 @min-[30rem]/column:col-span-1">
                        <span class="{{ $label }}">Nuevo ingreso</span>
                        @if ($intake === null)
                            <span class="{{ $figure }} text-neutral" data-testid="intake-{{ $campusCode }}">—</span>
                        @elseif ($mine)
                            <div class="group/cell flex flex-col gap-0.5" data-cell="{{ $intakeCell }}">
                                <div class="relative w-24">
                                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $maxExpected) }}" autocomplete="off" value="{{ $intake->new_students }}"
                                        oninput="this.value = this.value.replace(/\D/g, '')" wire:change="saveIntake('{{ $key }}', $event.target.value)"
                                        aria-label="Nuevo ingreso esperado{{ $where ? ' en '.$where : '' }}" @if ($intakeError) aria-invalid="true" aria-describedby="intake-error-{{ $campusCode }}" @endif
                                        @class(['input h-10 w-full px-2.5 text-right font-mono text-lg font-semibold tabular-nums group-data-[saved]/cell:input-success max-md:h-11', 'input-error' => $intakeError])
                                        data-testid="intake-{{ $campusCode }}" />
                                    <span class="pointer-events-none absolute start-2 top-1/2 hidden -translate-y-1/2 text-success group-data-[saved]/cell:block" aria-hidden="true"><x-icon name="o-check" class="block h-4 w-4" /></span>
                                </div>
                                <span class="sr-only" role="status" data-saved-status></span>
                                @if ($intakeError)
                                    <span id="intake-error-{{ $campusCode }}" class="text-xs text-error" data-testid="intake-error-{{ $campusCode }}">{{ $intakeError }}</span>
                                @endif
                            </div>
                        @else
                            <span class="{{ $figure }}" data-testid="intake-{{ $campusCode }}">{{ $intake->new_students }}</span>
                        @endif
                        @if ($intake !== null)
                            <span class="flex items-start gap-1 text-xs text-neutral" data-testid="intake-source-{{ $campusCode }}">
                                @if ($intake->suggested_new_students === null)
                                    <span>Sin proyección de nuevo ingreso</span>
                                @elseif ($intake->captured_at !== null)
                                    <x-icon name="o-pencil" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span>Corregido · Nexus <span class="font-mono">{{ $intake->suggested_new_students }}</span></span>
                                @else
                                    <x-icon name="o-sparkles" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span>Nexus <span class="font-mono">{{ $intake->suggested_new_students }}</span> · promedio de los últimos {{ $historyTerms }} {{ $termType }}</span>
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="{{ $label }} inline-flex items-center gap-1"><x-icon name="o-sparkles" class="h-3.5 w-3.5" aria-hidden="true" />Nexus</span>
                        <span class="{{ $figure }}" data-testid="packages-suggested-{{ $campusCode }}">{{ $figures['suggestedPackages'] ?? '—' }}</span>
                        <span class="text-xs text-neutral">paquetes de {{ $packages->groupSize }}</span>
                    </div>

                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="{{ $label }}">{{ $mine ? 'Abres' : 'Abre' }}</span>
                        <span class="{{ $figure }}" data-testid="packages-open-{{ $campusCode }}">{{ $figures['opens'] }}</span>
                        @if ($status)
                            <span @class(['flex items-start gap-1 text-xs font-medium', $statusTone[$status['tone']][1]]) data-testid="packages-status-{{ $campusCode }}">
                                @if ($statusTone[$status['tone']][0])
                                    <x-icon :name="$statusTone[$status['tone']][0]" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                @endif
                                <span>{{ $status['text'] }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($packages->packages === [])
        <div class="flex flex-wrap items-start gap-x-3 gap-y-3 border-t border-base-300 px-4 py-4 @min-[40rem]:px-5" data-testid="packages-empty">
            <x-icon name="o-rectangle-group" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
            <p class="min-w-0 flex-1 basis-64 text-sm"><span class="font-semibold">Todavía no hay paquetes.</span> <span class="text-neutral">Arma el primero con las materias de 1.º y, si hace falta, alguna de 2.º; luego di cuántos abre {{ $count > 1 ? 'cada campus' : 'el campus' }}.</span></p>
            @if ($packagesManageable && $packages->firstSemester !== [])
                <x-button label="Armar paquete con las materias de 1.º" icon="o-rectangle-group" class="btn-sm btn-primary max-md:min-h-11 max-md:w-full" wire:click="newPackage(true)" spinner="newPackage" data-opener data-testid="packages-first" />
            @endif
        </div>
    @else
        <div class="border-t border-base-300" data-testid="packages-list">
            <div class="hidden min-h-9 items-center gap-x-3.5 bg-base-200 px-5 @min-[48rem]:grid" style="grid-template-columns: {{ $rowTemplate }}" aria-hidden="true">
                <span class="{{ $label }}">Paquete</span>
                <span class="{{ $label }}">Materias</span>
                @foreach ($columns as $column)
                    <span @class([$label, '-my-2 self-stretch py-2.5 text-right', 'bg-secondary/40 px-2.5' => ! $column['editable']])>{{ $column['campus']?->code ?? 'Alumnos' }}</span>
                @endforeach
                <span></span>
            </div>

            @foreach ($packages->packages as $entry)
                @php
                    $package = $entry['package'];
                @endphp
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3.5 gap-y-2 border-t border-base-300 px-4 py-2.5 first:border-t-0 @min-[48rem]:[grid-template-columns:var(--package-row)] @min-[48rem]:px-5"
                    style="--package-row: {{ $rowTemplate }}"
                    wire:key="package-{{ $package->id }}-{{ $rejections }}-{{ collect($entry['counts'])->map(fn ($column) => $column->count)->implode('.') }}" data-testid="package-{{ $package->code }}">
                    <div class="col-start-1 row-start-1 flex min-w-0 flex-col leading-snug @min-[48rem]:col-start-auto @min-[48rem]:row-start-auto">
                        <span class="truncate font-semibold" title="{{ $entry['label'] }}">{{ $entry['label'] }}</span>
                        <span class="text-xs text-neutral">{{ count($entry['subjects']) }} {{ count($entry['subjects']) === 1 ? 'materia' : 'materias' }}</span>
                    </div>

                    <div class="col-span-2 row-start-2 flex min-w-0 flex-wrap gap-1.5 @min-[48rem]:col-span-1 @min-[48rem]:row-start-auto">
                        @foreach ($entry['subjects'] as $subject)
                            <span @class([
                                'inline-flex h-[22px] items-center gap-1 rounded-[4px] border bg-base-100 px-1.5 text-xs',
                                'border-base-300' => $subject->semester === 1,
                                'border-dashed border-base-content/40' => $subject->semester !== 1,
                            ]) title="{{ $subject->subject->name }}" data-testid="package-chip-{{ $subject->subject->code }}">
                                <span class="font-mono font-semibold">{{ $subject->subject->code }}</span>
                                <span class="sr-only">{{ $subject->subject->name }}</span>
                                @if ($subject->semester !== 1)
                                    <span class="text-[11px] text-neutral">{{ $subject->semester }}.º</span>
                                @endif
                            </span>
                        @endforeach
                    </div>

                    <div class="col-span-2 row-start-3 flex flex-wrap gap-2 @min-[48rem]:contents">
                        @foreach ($columns as $key => $column)
                            @php
                                $packageCount = $entry['counts'][$key] ?? null;
                                $mine = $column['editable'] && $open;
                                $campusCode = $column['campus']?->code ?? 'unica';
                                $where = $column['campus']?->code;
                                $countCell = "package.{$package->id}.{$key}";
                                $countError = $cellErrors[$countCell] ?? null;
                            @endphp
                            <div @class([
                                'group/cell flex min-h-11 min-w-0 flex-col justify-center gap-0.5 rounded-field border border-base-300 px-2 py-1 @min-[48rem]:-my-2.5 @min-[48rem]:min-h-full @min-[48rem]:self-stretch @min-[48rem]:rounded-none @min-[48rem]:border-0 @min-[48rem]:py-2',
                                'bg-secondary/40 @min-[48rem]:px-2.5' => ! $column['editable'],
                                '@min-[48rem]:px-0' => $column['editable'],
                            ]) data-cell="{{ $countCell }}">
                                <div class="flex items-center justify-between gap-2 @min-[48rem]:justify-end">
                                    <span class="flex shrink-0 items-center gap-1 text-neutral @min-[48rem]:hidden">
                                        <x-academic-load::campus-tag :campus="$column['campus']" />
                                        @if (! $column['editable'])
                                            <x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />
                                        @endif
                                    </span>
                                    @if ($packageCount === null)
                                        <span class="text-sm text-neutral">—</span>
                                    @elseif ($mine)
                                        <div class="relative w-[4.5rem] @min-[48rem]:w-full">
                                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" value="{{ $packageCount->count }}"
                                                oninput="this.value = this.value.replace(/\D/g, '')" wire:change="savePackageCount('{{ $package->id }}', '{{ $key }}', $event.target.value)"
                                                aria-label="Cuántos {{ $entry['label'] }} abre {{ $where ?? 'la modalidad' }}" @if ($countError) aria-invalid="true" aria-describedby="package-count-error-{{ $package->id }}-{{ $campusCode }}" @endif
                                                @class(['input input-sm w-full px-2 text-right font-mono text-sm tabular-nums group-data-[saved]/cell:input-success max-md:h-10', 'input-error' => $countError])
                                                data-testid="package-count-{{ $package->code }}-{{ $campusCode }}" />
                                            <span class="pointer-events-none absolute start-1.5 top-1/2 hidden -translate-y-1/2 text-success group-data-[saved]/cell:block" aria-hidden="true"><x-icon name="o-check" class="block h-4 w-4" /></span>
                                        </div>
                                    @else
                                        <span class="font-mono text-sm font-semibold tabular-nums" data-testid="package-count-{{ $package->code }}-{{ $campusCode }}"><span class="sr-only">{{ $entry['label'] }}{{ $where ? ' en '.$where : '' }}: </span>{{ $packageCount->count }}</span>
                                    @endif
                                </div>
                                <span class="sr-only" role="status" data-saved-status></span>
                                @if ($countError)
                                    <span id="package-count-error-{{ $package->id }}-{{ $campusCode }}" class="text-xs text-error" data-testid="package-count-error-{{ $package->code }}-{{ $campusCode }}">{{ $countError }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <x-button :icon="$packagesManageable ? 'o-pencil-square' : 'o-eye'" class="btn-ghost btn-sm btn-square col-start-2 row-start-1 justify-self-end max-md:h-11 max-md:w-11 @min-[48rem]:col-start-auto @min-[48rem]:row-start-auto"
                        wire:click="editPackage('{{ $package->id }}')" spinner="editPackage('{{ $package->id }}')" :aria-label="($packagesManageable ? 'Editar ' : 'Ver ').$entry['label']" :title="($packagesManageable ? 'Editar ' : 'Ver ').$entry['label']" data-opener data-testid="package-edit-{{ $package->code }}" />
                </div>
            @endforeach
        </div>
    @endif
</section>
