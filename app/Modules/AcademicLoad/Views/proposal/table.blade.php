{{--
    La vista «Tabla» de la propuesta (maqueta B): una sección por semestre y una fila por materia, con los alumnos
    esperados y los grupos como campos de texto numérico (sin la rueda que cambia el número) que guardan al salir del
    campo. Con volumen: el encabezado de columnas se queda fijo bajo la banda al desplazarse y los totales (materias
    con grupos de todas, grupos y alumnos posibles) van en una barra pegada al pie. Si toda la propuesta está
    bloqueada (`$generalLock`), el motivo se dice una vez, en una alerta sobre la tabla; por fila solo lo que es
    distinto («CB ya aceptó su parte»). Una fila de solo lectura muestra sus
    números como texto, no como campos deshabilitados. La fila se pinta por el estado de la parte de su departamento
    (regresada, aceptada o pendiente) y lleva su icono; las anomalías van con icono y texto. El filtro de grupos
    («Con grupos», «Sin grupos») deja solo sus filas. Con un departamento elegido, las filas de los demás van con
    fondo tenue, o se ocultan con «Mostrar solo».

    Cada fila tiene una acción que abre el mismo panel que el mosaico (también de solo lectura) y dice su nota a la
    vista. Una celda guardada lo dice con una palomita un instante (`cell-saved`, que atiende la página) y una
    rechazada, con su error debajo. La tabla no pasa de 64 rem (`data-table-width`) para que los números no queden
    lejos de la materia en una pantalla ancha.
--}}
<div class="max-w-5xl space-y-3" data-testid="proposal-table" data-table-width>
    @if ($generalLock)
        <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$generalLock->message('')" data-testid="table-lock" />
    @endif

    <div>
        <div class="sticky top-(--shell-top) z-10 grid grid-cols-[6.5rem_minmax(0,1fr)_3rem_4.5rem_5.5rem_5.5rem_2.25rem] gap-x-3 rounded-t-box border border-base-300 bg-base-100 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden" aria-hidden="true">
            <span>Clave</span><span>Materia</span><span>Depto.</span><span class="text-right">Elegibles</span><span class="text-right">Esperados</span><span class="text-right">Grupos</span><span></span>
        </div>

        <div class="space-y-3 md:space-y-0">
            @forelse ($tableSemesters as $semester => $items)
                @php
                    $groups = collect($items)->sum('requested_groups');
                @endphp
                <section aria-label="Semestre {{ $semester }}" class="overflow-hidden border border-base-300 bg-base-100 max-md:rounded-box md:border-t-0 md:last:rounded-b-box" wire:key="table-semester-{{ $semester }}">
                    <div class="flex items-center justify-between gap-2 border-b border-base-300 bg-base-200 px-4 py-2">
                        <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Semestre {{ $semester }}</h3>
                        <span class="font-mono text-sm text-neutral">{{ $groups }} {{ $groups === 1 ? 'grupo' : 'grupos' }}</span>
                    </div>
                    @foreach ($items as $item)
                        @php
                            $lock = $locks[$item->id] ?? null;
                            $readonly = $lock !== null || ! $canEdit;
                            $code = $item->subject->department->code;
                            $part = $parts[$item->subject->department_id];
                            $dimmed = $department !== '' && $code !== $department;
                            $rowTone = match (true) {
                                $dimmed, $part === 'accepted' => 'bg-base-200 text-neutral',
                                $part === 'returned' => 'bg-error/10 text-base-content',
                                default => 'bg-base-100 text-base-content',
                            };
                            $anomalies = $overview->anomaliesFor($item);
                            $ownLock = $lock !== null && ! $lock->isGeneral() ? $lock->short($code) : null;
                            $cells = [['expected_students', 'Esperados', $item->expected_students, $maxExpected], ['requested_groups', 'Grupos', $item->requested_groups, $maxGroups]];
                        @endphp
                        <div wire:key="row-{{ $item->id }}-{{ $rejections }}-{{ $item->expected_students }}-{{ $item->requested_groups }}" data-testid="row-{{ $item->subject->code }}" data-part="{{ $part }}" data-item="{{ $item->id }}" tabindex="-1"
                            @class([
                                'grid items-center gap-x-3 gap-y-1 px-4 py-2 [&:not(:first-of-type)]:border-t border-base-300 grid-cols-3 md:grid-cols-[6.5rem_minmax(0,1fr)_3rem_4.5rem_5.5rem_5.5rem_2.25rem] outline-none data-[found]:ring-2 data-[found]:ring-inset data-[found]:ring-error',
                                $rowTone,
                            ])>
                            <span class="flex items-center gap-1.5 font-mono text-sm font-semibold max-md:col-span-3">
                                @if ($part === 'returned')
                                    <x-icon name="o-arrow-uturn-left" class="w-4 h-4 shrink-0 text-error" aria-hidden="true" /><span class="sr-only">Regresada por {{ $code }}.</span>
                                @elseif ($part === 'accepted')
                                    <x-icon :name="$generalLock === null ? 'o-lock-closed' : 'o-check-circle'" class="w-4 h-4 shrink-0" aria-hidden="true" /><span class="sr-only">Aceptada por {{ $code }}.</span>
                                @endif
                                {{ $item->subject->code }}
                            </span>
                            <span class="min-w-0 truncate max-md:col-span-3" title="{{ $item->subject->name }}">
                                {{ $item->subject->name }}
                            </span>
                            <span class="font-mono text-xs max-md:hidden">{{ $code }}</span>
                            <span class="flex flex-col md:items-end">
                                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] md:sr-only">Elegibles</span>
                                <span class="font-mono text-sm tabular-nums">{{ $item->eligible_students }}</span>
                            </span>
                            @foreach ($cells as [$field, $label, $value, $max])
                                @php
                                    $cell = "{$item->id}.{$field}";
                                    $cellError = $cellErrors[$cell] ?? null;
                                @endphp
                                <div class="group/cell flex flex-col gap-0.5 md:items-stretch" data-cell="{{ $cell }}">
                                    @if ($readonly)
                                        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] md:sr-only">{{ $label }}</span>
                                        <span class="font-mono text-sm tabular-nums md:text-right" data-readonly-cell="{{ $field }}">{{ $value }}</span>
                                    @else
                                        <label for="cell-{{ $item->id }}-{{ $field }}" class="text-[11.5px] font-medium uppercase tracking-[0.09em] md:sr-only">{{ $label }}</label>
                                        <div class="relative">
                                            <input type="text" id="cell-{{ $item->id }}-{{ $field }}" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $max) }}" autocomplete="off" value="{{ $value }}"
                                                oninput="this.value = this.value.replace(/\D/g, '')" wire:change="saveCell('{{ $item->id }}', '{{ $field }}', $event.target.value)"
                                                aria-label="{{ $label }} de {{ $item->subject->code }}" @if ($cellError) aria-invalid="true" aria-describedby="cell-error-{{ $item->id }}-{{ $field }}" @endif
                                                @class(['input input-sm w-full text-right font-mono text-sm tabular-nums group-data-[saved]/cell:input-success', 'input-error' => $cellError]) data-field="{{ $field }}" />
                                            {{-- En un span: el icono de MaryUI trae `inline`, que le gana a `hidden`. --}}
                                            <span class="pointer-events-none absolute start-2 top-1/2 hidden -translate-y-1/2 text-success group-data-[saved]/cell:block" aria-hidden="true"><x-icon name="o-check" class="block h-4 w-4" /></span>
                                        </div>
                                        <span class="sr-only" role="status" data-saved-status></span>
                                        @if ($cellError)
                                            <span id="cell-error-{{ $item->id }}-{{ $field }}" class="text-xs text-error md:text-right" data-testid="cell-error-{{ $item->subject->code }}-{{ $field }}">{{ $cellError }}</span>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" wire:click="edit('{{ $item->id }}')" data-opener data-testid="open-{{ $item->subject->code }}"
                                class="btn btn-ghost btn-sm max-md:col-span-3 max-md:justify-self-start md:btn-square">
                                <x-icon :name="$readonly ? 'o-eye' : 'o-pencil-square'" class="h-4 w-4" aria-hidden="true" />
                                <span class="md:sr-only">{{ $readonly ? 'Ver' : 'Editar' }} <span class="font-mono">{{ $item->subject->code }}</span> en el panel</span>
                            </button>
                            @if ($ownLock || $anomalies !== [] || $item->notes)
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm max-md:col-span-3 md:col-span-full">
                                    @if ($ownLock)
                                        <span class="flex items-center gap-1.5"><x-icon name="o-lock-closed" class="w-4 h-4 shrink-0" aria-hidden="true" />{{ $ownLock }}</span>
                                    @endif
                                    @if ($item->notes)
                                        <span class="flex min-w-0 items-center gap-1.5" data-testid="note-{{ $item->subject->code }}">
                                            <x-icon name="o-chat-bubble-left-ellipsis" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="truncate"><span class="font-medium">Nota:</span> {{ $item->notes }}</span>
                                        </span>
                                    @endif
                                    @if ($anomalies !== [])
                                        <span class="flex items-center gap-1.5 font-medium text-warning" data-testid="anomaly-{{ $item->subject->code }}">
                                            <x-icon name="o-exclamation-triangle" class="w-4 h-4 shrink-0" aria-hidden="true" /><span class="sr-only">Revisar: </span>{{ implode('; ', $anomalies) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </section>
            @empty
                <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">No hay materias que mostrar.</div>
            @endforelse
        </div>
    </div>

    @include('academic-load::proposal.totals', ['floating' => true])
</div>
