{{--
    La vista «Tabla» de la propuesta (maqueta U1, opción A «Columnas por campus», elegida por el usuario el 12 de
    noviembre de 2026): una sección por semestre y un renglón por materia, con un grupo de columnas por campus (alumnos
    posibles, esperados y grupos) lado a lado y, si hay más de un campus, el total entre campus (posibles y grupos). Los
    esperados y los grupos de una columna que el usuario corrige son campos de texto numérico (sin la rueda que cambia el
    número) que guardan al salir del campo; los de una columna de otro campus (M7) van como texto, sobre un fondo tenue
    en toda la columna y con «Solo lectura» en su encabezado. Con volumen: el encabezado de columnas se queda fijo bajo
    la banda al desplazarse y los totales (materias con grupos de todas, grupos con los de cada campus y alumnos
    posibles) van en una barra pegada al pie.

    Con la caja más angosta de lo que pide la tabla ancha (consulta de contenedor: 55 rem, 880 px, con uno o dos campus;
    69 rem con tres, 82 rem con cuatro y 96 rem con más) cada materia es una ficha con una tablita campus × cifras y el
    total, como en la maqueta; así la columna del total y la acción nunca se cortan. Las dos formas están en la página y
    la consulta de contenedor muestra una; los campos de la ficha llevan otro id (`card-cell-…`). Como el encabezado de
    la tabla ancha es solo visual (`aria-hidden`), cada cifra de solo lectura y cada «Posibles» dice su campo y su campus
    para lectores de pantalla. La columna de solo lectura va en `secondary/40`, que se distingue en claro y en oscuro.

    Si toda la propuesta está bloqueada (`$generalLock`), el motivo se dice una vez, en una alerta sobre la tabla; por
    renglón solo lo que es distinto («CB ya aceptó su parte»). El renglón se pinta por el estado de la parte de su
    departamento (regresada, aceptada o pendiente) y lleva su icono; las anomalías van con icono y texto, con el campus
    de la fila. El filtro de grupos («Con grupos», «Sin grupos») deja solo sus renglones. Con un departamento elegido,
    los renglones de los demás van con fondo tenue, o se ocultan con «Mostrar solo». Cada renglón tiene una acción que
    abre el panel de la materia (también de solo lectura). Una celda guardada lo dice con una palomita un instante
    (`cell-saved`, que atiende la página) y una rechazada, con su error debajo. Con hasta tres campus la tabla no pasa de
    72 rem (`data-table-width`) para que los números no queden lejos de la materia en una pantalla ancha.
--}}
@php
    $multi = $overview->isMultiCampus();
    $template = '6.5rem minmax(8rem,1fr) repeat('.count($columns).', 12.75rem)'.($multi ? ' 6.75rem' : '').' 2.25rem';
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $sublabel = 'text-right text-xs font-medium text-neutral';
    [$wide, $narrow, $maxWidth] = match (true) {
        count($columns) <= 2 => ['@max-[55rem]:hidden', '@min-[55rem]:hidden', 'max-w-6xl'],
        count($columns) === 3 => ['@max-[69rem]:hidden', '@min-[69rem]:hidden', 'max-w-6xl'],
        count($columns) === 4 => ['@max-[82rem]:hidden', '@min-[82rem]:hidden', 'max-w-none'],
        default => ['@max-[96rem]:hidden', '@min-[96rem]:hidden', 'max-w-none'],
    };
@endphp
<div @class(['@container space-y-3', $maxWidth]) data-testid="proposal-table" data-table-width>
    @if ($generalLock)
        <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$generalLock->message('')" data-testid="table-lock" />
    @endif

    {{-- Ancho: las columnas de cada campus lado a lado. --}}
    <div class="{{ $wide }}">
        <div class="sticky top-(--shell-top) z-10 grid items-end gap-x-3 rounded-t-box border border-base-300 bg-base-100 px-4 py-2" style="grid-template-columns: {{ $template }}" aria-hidden="true">
            <span class="{{ $label }}">Clave</span>
            <span class="{{ $label }}">Materia</span>
            @foreach ($columns as $key => $column)
                <div @class(['-my-2 -me-3 grid grid-cols-[3rem_4.25rem_3.75rem] gap-x-2 border-s border-base-300 py-2 ps-2 pe-3', 'bg-secondary/40' => ! $column['editable']])
                    data-column="{{ $column['campus']?->code ?? 'unica' }}" @if (! $column['editable']) data-readonly-column="{{ $column['campus']?->code ?? 'unica' }}" @endif>
                    <span class="col-span-3 mb-1 flex min-w-0 items-center gap-1.5 text-sm font-semibold">
                        <x-academic-load::campus-tag :campus="$column['campus']" />
                        <span class="truncate">{{ $column['campus']?->name ?? 'Alumnos de la modalidad' }}</span>
                        @if (! $column['editable'])
                            <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-normal text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" />Solo lectura</span>
                        @endif
                    </span>
                    <span class="{{ $sublabel }}">Posibles</span>
                    <span class="{{ $sublabel }}">Esperados</span>
                    <span class="{{ $sublabel }}">Grupos</span>
                </div>
            @endforeach
            @if ($multi)
                <div class="-my-2 grid grid-cols-2 gap-x-2 border-s border-base-300 py-2 ps-2" data-column-total>
                    <span class="col-span-2 mb-1 text-sm font-semibold">Total</span>
                    <span class="{{ $sublabel }}">Posibles</span>
                    <span class="{{ $sublabel }}">Grupos</span>
                </div>
            @endif
            <span></span>
        </div>

        @forelse ($tableSemesters as $semester => $rows)
            <section aria-label="Semestre {{ $semester }}" class="overflow-hidden border border-t-0 border-base-300 bg-base-100 last:rounded-b-box" wire:key="table-semester-{{ $semester }}">
                @include('academic-load::proposal.semester-head', ['rows' => $rows])
                @foreach ($rows as $row)
                    @php
                        $code = $row->subject->department->code;
                        $part = $parts[$row->subject->department_id];
                        $dimmed = $department !== '' && $code !== $department;
                        $rowTone = match (true) {
                            $dimmed, $part === 'accepted' => 'bg-base-200 text-neutral',
                            $part === 'returned' => 'bg-error/10 text-base-content',
                            default => 'bg-base-100 text-base-content',
                        };
                    @endphp
                    <div wire:key="row-{{ $row->id() }}-{{ $rejections }}-{{ implode('-', array_map(fn ($item) => $item->expected_students.'.'.$item->requested_groups, $row->items())) }}" data-testid="row-{{ $row->subject->code }}" data-part="{{ $part }}" data-item="{{ $row->id() }}" tabindex="-1"
                        @class(['grid items-center gap-x-3 px-4 py-2 outline-none [&:not(:nth-child(2))]:border-t border-base-300 data-[found]:ring-2 data-[found]:ring-inset data-[found]:ring-error', $rowTone])
                        style="grid-template-columns: {{ $template }}">
                        @include('academic-load::proposal.subject-cell', ['row' => $row, 'code' => $code, 'part' => $part])
                        @foreach ($columns as $key => $column)
                            @php
                                $item = $row->cells[$key] ?? null;
                            @endphp
                            <div @class(['-my-2 -me-3 grid min-h-full grid-cols-[3rem_4.25rem_3.75rem] items-center gap-x-2 self-stretch border-s border-base-300 py-2 ps-2 pe-3', 'bg-secondary/40' => ! $column['editable'] && ! $dimmed && $part !== 'accepted'])>
                                @if ($item === null)
                                    <span class="col-span-3 text-center text-sm text-neutral">—</span>
                                @else
                                    <span class="text-right font-mono text-sm tabular-nums text-neutral"><span class="sr-only">Alumnos posibles{{ $column['campus'] ? ' en '.$column['campus']->code : '' }}: </span>{{ $item->eligible_students }}</span>
                                    @foreach ([['expected_students', 'Esperados', $item->expected_students, $maxExpected], ['requested_groups', 'Grupos', $item->requested_groups, $maxGroups]] as [$field, $fieldLabel, $value, $max])
                                        @include('academic-load::proposal.cell', ['item' => $item, 'column' => $column, 'field' => $field, 'fieldLabel' => $fieldLabel, 'value' => $value, 'max' => $max, 'prefix' => 'cell', 'subjectCode' => $row->subject->code])
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                        @if ($multi)
                            <div class="-my-2 grid grid-cols-2 items-center gap-x-2 self-stretch border-s border-base-300 py-2 ps-2" data-row-total>
                                <span class="text-right font-mono text-sm tabular-nums">{{ $row->eligible() }}</span>
                                <span class="text-right font-mono text-sm font-semibold tabular-nums">{{ $row->groups() }}</span>
                            </div>
                        @endif
                        @include('academic-load::proposal.open-button', ['row' => $row])
                    </div>
                @endforeach
            </section>
        @empty
            <div class="rounded-b-box border border-t-0 border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">No hay materias que mostrar.</div>
        @endforelse
    </div>

    {{-- Angosto: una ficha por materia con una tablita campus × cifras. --}}
    <div class="space-y-3 {{ $narrow }}" data-testid="proposal-cards">
        @forelse ($tableSemesters as $semester => $rows)
            <section aria-label="Semestre {{ $semester }}" class="overflow-hidden rounded-box border border-base-300 bg-base-100" wire:key="card-semester-{{ $semester }}">
                @include('academic-load::proposal.semester-head', ['rows' => $rows])
                @foreach ($rows as $row)
                    @php
                        $code = $row->subject->department->code;
                        $part = $parts[$row->subject->department_id];
                        $dimmed = $department !== '' && $code !== $department;
                        $rowTone = match (true) {
                            $dimmed, $part === 'accepted' => 'bg-base-200 text-neutral',
                            $part === 'returned' => 'bg-error/10 text-base-content',
                            default => 'bg-base-100 text-base-content',
                        };
                    @endphp
                    <div wire:key="card-{{ $row->id() }}-{{ $rejections }}-{{ implode('-', array_map(fn ($item) => $item->expected_students.'.'.$item->requested_groups, $row->items())) }}" data-item="{{ $row->id() }}" tabindex="-1"
                        @class(['space-y-2 px-4 py-3 outline-none [&:not(:nth-child(2))]:border-t border-base-300 data-[found]:ring-2 data-[found]:ring-inset data-[found]:ring-error', $rowTone])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1 space-y-0.5">
                                @include('academic-load::proposal.subject-cell', ['row' => $row, 'code' => $code, 'part' => $part])
                            </div>
                            @include('academic-load::proposal.open-button', ['row' => $row])
                        </div>
                        <div class="grid grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5">
                            <span></span>
                            <span class="{{ $label }} text-right">Posibles</span>
                            <span class="{{ $label }} text-right">Esperados</span>
                            <span class="{{ $label }} text-right">Grupos</span>
                            @foreach ($columns as $key => $column)
                                @php
                                $item = $row->cells[$key] ?? null;
                            @endphp
                                <span class="flex items-center gap-1 text-neutral">
                                    <x-academic-load::campus-tag :campus="$column['campus']" />
                                    @if ($column['campus'] === null)<span class="text-sm">Alumnos</span>@endif
                                    @if (! $column['editable'])
                                        <x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Solo lectura</span>
                                    @endif
                                </span>
                                @if ($item === null)
                                    <span class="col-span-3 text-center text-sm text-neutral">—</span>
                                @else
                                    <span class="text-right font-mono text-sm tabular-nums text-neutral"><span class="sr-only">Alumnos posibles{{ $column['campus'] ? ' en '.$column['campus']->code : '' }}: </span>{{ $item->eligible_students }}</span>
                                    @foreach ([['expected_students', 'Esperados', $item->expected_students, $maxExpected], ['requested_groups', 'Grupos', $item->requested_groups, $maxGroups]] as [$field, $fieldLabel, $value, $max])
                                        @include('academic-load::proposal.cell', ['item' => $item, 'column' => $column, 'field' => $field, 'fieldLabel' => $fieldLabel, 'value' => $value, 'max' => $max, 'prefix' => 'card-cell', 'subjectCode' => $row->subject->code])
                                    @endforeach
                                @endif
                            @endforeach
                            @if ($multi)
                                <span class="border-t border-base-300 pt-1.5 text-sm font-semibold">Total</span>
                                <span class="border-t border-base-300 pt-1.5 text-right font-mono text-sm font-semibold tabular-nums">{{ $row->eligible() }}</span>
                                <span class="border-t border-base-300 pt-1.5 text-right font-mono text-sm font-semibold tabular-nums">{{ $row->expected() }}</span>
                                <span class="border-t border-base-300 pt-1.5 text-right font-mono text-sm font-semibold tabular-nums">{{ $row->groups() }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>
        @empty
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral">No hay materias que mostrar.</div>
        @endforelse
    </div>

    @include('academic-load::proposal.totals', ['floating' => true])
</div>
