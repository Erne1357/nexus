{{--
    La vista «Retícula» de la propuesta (maqueta C, DESIGN.md "Propuesta de carrera"): semestres en columnas con los
    mosaicos de Core (core::semester-grid), uno por materia, y las bandas de especialidad del plan igual que en la
    retícula del plan y la del alumno. Cada mosaico dice, de arriba abajo, su clave y el icono del estado de la parte
    de su departamento, el nombre, «Eleg.» con el departamento y «Grupos» con la advertencia si la fila es rara. Lo
    pinta el estado de esa parte (`$parts`): regresada en error con su icono, aceptada apagada con candado (o con
    palomita si toda la propuesta está bloqueada, `$generalLock`) y pendiente neutra; sin grupos va punteado. Con un
    departamento elegido (`data-focus` en el marco) las materias de los demás van con fondo y borde tenues, sin bajar
    la opacidad, y el encabezado de cada semestre cuenta los grupos de ese departamento.
--}}
@if ($layout->columns === [])
    <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">Esta propuesta no tiene materias.</div>
@else
    <x-core::semester-grid.frame :layout="$layout" data-testid="proposal-grid" :data-focus="$department !== '' ? $department : null">
        @foreach ($overview->specialties as $specialty)
            <x-core::semester-grid.band :specialty="$specialty['id']" :row="$layout->bands[$specialty['id']]" :name="$specialty['name']" :subjects="$specialty['subjects']" />
        @endforeach

        @foreach ($gridSemesters as $semester => $items)
            @php
                $groups = collect($items)->when($department !== '', fn ($rows) => $rows->filter(fn ($item) => $item->subject->department->code === $department))->sum('requested_groups');
            @endphp
            <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" :note="$department !== '' ? 'grupos de '.$department : null" wire:key="semester-{{ $semester }}">
                <x-slot:figures><span data-figure="{{ $semester }}">{{ $groups }} {{ $groups === 1 ? 'grupo' : 'grupos' }}</span></x-slot:figures>

                @foreach ($items as $item)
                    @php
                        $requested = $item->requested_groups > 0;
                        $code = $item->subject->department->code;
                        $part = $parts[$item->subject->department_id];
                        $dimmed = $department !== '' && $code !== $department;
                        $anomalies = $overview->anomaliesFor($item);
                        $specialty = $overview->specialtyFor($item);
                        $tone = match (true) {
                            $dimmed => 'border-base-300 bg-base-200/60 text-neutral',
                            $part === 'returned' => 'border-error bg-error/10 text-error',
                            $part === 'accepted' => 'border-base-300 bg-base-200 text-neutral',
                            ! $requested => 'border-base-content/30 bg-base-200 text-base-content',
                            default => 'border-base-content/30 bg-base-100 text-base-content',
                        };
                        $classes = Illuminate\Support\Arr::toCssClasses([$tone, 'border-dashed' => ! $requested]);
                        $title = implode(' · ', array_filter([isset($locks[$item->id]) ? $locks[$item->id]->message($code) : null, ...$anomalies]));
                    @endphp
                    @if ($specialty !== null && ($loop->first || $overview->specialtyFor($items[$loop->index - 1]) !== $specialty))
                        <x-core::semester-grid.separator :specialty="$specialty" :name="$specialtyNames[$specialty]" wire:key="separator-{{ $semester }}-{{ $specialty }}" />
                    @endif

                    <x-core::semester-grid.tile :row="$layout->rows[$item->subject->code]" :code="$item->subject->code" :name="$item->subject->name" soft :muted="$dimmed || $part === 'accepted' || ! $requested"
                        :class="$classes"
                        wire:click="edit('{{ $item->id }}')" wire:key="tile-{{ $item->id }}" :data-me="$itemId === $item->id ? '' : null"
                        :data-on-chain="$department !== '' && $code === $department ? '' : null"
                        :title="$title !== '' ? $title : null" data-opener data-testid="tile-{{ $item->subject->code }}" data-part="{{ $part }}">
                        <x-slot:icon>
                            @if ($part === 'returned')
                                <x-icon name="o-arrow-uturn-left" class="w-4 h-4 shrink-0" aria-hidden="true" />
                                <span class="sr-only">Regresada por {{ $code }}</span>
                            @elseif ($part === 'accepted')
                                @if ($generalLock === null)
                                    <x-icon name="o-lock-closed" class="w-4 h-4 shrink-0" aria-hidden="true" data-lock />
                                    <span class="sr-only">Aceptada por {{ $code }}: ya no cambia</span>
                                @else
                                    <x-icon name="o-check-circle" class="w-4 h-4 shrink-0" aria-hidden="true" />
                                    <span class="sr-only">Aceptada por {{ $code }}</span>
                                @endif
                            @endif
                        </x-slot:icon>
                        <x-slot:requisites class="flex items-baseline justify-between gap-1"><span class="truncate">Eleg. <span class="font-mono">{{ $item->eligible_students }}</span></span><span class="font-mono">{{ $code }}</span></x-slot:requisites>
                        <x-slot:start><span class="font-sans">Grupos</span> <span class="font-semibold">{{ $item->requested_groups }}</span></x-slot:start>
                        <x-slot:end>
                            @if ($anomalies !== [])
                                <span class="inline-flex items-center text-warning" data-testid="anomaly-{{ $item->subject->code }}">
                                    <x-icon name="o-exclamation-triangle" class="w-4 h-4" aria-hidden="true" />
                                    <span class="sr-only">Revisar: {{ implode('; ', $anomalies) }}</span>
                                </span>
                            @endif
                        </x-slot:end>
                    </x-core::semester-grid.tile>
                @endforeach
            </x-core::semester-grid.semester>
        @endforeach
    </x-core::semester-grid.frame>
@endif
