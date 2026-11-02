{{--
    La vista «Tabla» de la propuesta (maqueta B): una sección por semestre y una fila por materia, con los
    alumnos esperados y los grupos como campos numéricos que guardan al salir del campo (Tab pasa al siguiente).
    Una fila bloqueada deshabilita sus campos y dice por qué; con un departamento elegido, las filas de los demás
    se atenúan, o se ocultan con el filtro.
--}}
@php($maxLength = \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_NOTES_LENGTH)
<div class="space-y-4" data-testid="proposal-table">
    @forelse ($tableSemesters as $semester => $items)
        <section aria-label="Semestre {{ $semester }}" class="overflow-hidden rounded-box border border-base-300 bg-base-100" wire:key="table-semester-{{ $semester }}">
            <div class="flex items-center justify-between gap-2 border-b border-base-300 bg-base-200/60 px-4 py-2">
                <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Semestre {{ $semester }}</h3>
                <span class="font-mono text-xs text-neutral">{{ collect($items)->sum('requested_groups') }} gr</span>
            </div>
            <div class="grid grid-cols-[6.5rem_minmax(0,1fr)_3rem_4.5rem_5.5rem_5.5rem] gap-x-3 px-4 pt-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden" aria-hidden="true">
                <span>Clave</span><span>Materia</span><span>Depto.</span><span class="text-right">Elegibles</span><span class="text-right">Esperados</span><span class="text-right">Grupos</span>
            </div>
            @foreach ($items as $item)
                @php($lock = $locks[$item->id] ?? null)
                @php($readonly = $lock !== null || ! $canEdit)
                @php($dimmed = $department !== '' && $item->subject->department->code !== $department)
                <div wire:key="row-{{ $item->id }}-{{ $rejections }}-{{ $item->expected_students }}-{{ $item->requested_groups }}" data-testid="row-{{ $item->subject->code }}"
                    @class([
                        'grid items-center gap-x-3 gap-y-1 px-4 py-2 [&:not(:first-of-type)]:border-t border-base-300 grid-cols-[minmax(0,1fr)_auto_auto] md:grid-cols-[6.5rem_minmax(0,1fr)_3rem_4.5rem_5.5rem_5.5rem]',
                        'opacity-35' => $dimmed,
                    ])>
                    <span class="font-mono text-sm font-semibold max-md:col-span-3">{{ $item->subject->code }}</span>
                    <span class="min-w-0 truncate max-md:col-span-3" title="{{ $item->subject->name }}">
                        {{ $item->subject->name }}
                        @if ($item->notes)<x-icon name="o-chat-bubble-left-ellipsis" class="ms-1 inline w-4 h-4 text-neutral" title="{{ $item->notes }}" />@endif
                    </span>
                    <span class="font-mono text-xs text-neutral max-md:hidden">{{ $item->subject->department->code }}</span>
                    <span class="text-right font-mono text-sm text-neutral tabular-nums" title="Elegibles"><span class="md:hidden">Elegibles </span>{{ $item->eligible_students }}</span>
                    <label class="flex items-center gap-1.5 text-sm md:contents">
                        <span class="text-neutral md:sr-only">Esperados</span>
                        <input type="number" min="0" max="{{ \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_EXPECTED_STUDENTS }}" value="{{ $item->expected_students }}" @disabled($readonly)
                            wire:change="saveCell('{{ $item->id }}', 'expected_students', $event.target.value)" aria-label="Esperados de {{ $item->subject->code }}"
                            class="input input-sm w-full text-right font-mono tabular-nums" data-field="expected_students" />
                    </label>
                    <label class="flex items-center gap-1.5 text-sm md:contents">
                        <span class="text-neutral md:sr-only">Grupos</span>
                        <input type="number" min="0" max="{{ \App\Modules\AcademicLoad\Rules\ProposalItemRules::MAX_REQUESTED_GROUPS }}" value="{{ $item->requested_groups }}" @disabled($readonly)
                            wire:change="saveCell('{{ $item->id }}', 'requested_groups', $event.target.value)" aria-label="Grupos de {{ $item->subject->code }}"
                            class="input input-sm w-full text-right font-mono tabular-nums" data-field="requested_groups" />
                    </label>
                    @if ($lock)
                        <span class="flex items-center gap-1.5 text-[12.5px] text-neutral max-md:col-span-3 md:col-span-full"><x-icon name="o-lock-closed" class="w-4 h-4 shrink-0" aria-hidden="true" />{{ $lock }}</span>
                    @endif
                </div>
            @endforeach
        </section>
    @empty
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">No hay materias que mostrar.</div>
    @endforelse
</div>
