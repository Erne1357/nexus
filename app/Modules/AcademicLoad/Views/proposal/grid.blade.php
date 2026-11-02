{{--
    La vista «Retícula» de la propuesta (maqueta C; mismas piezas y medidas que "Retícula del alumno" y "Retícula del
    plan (editor)" de DESIGN.md): semestres en columnas con los mosaicos de Core (core::semester-grid), uno por
    materia, y las bandas de especialidad del plan. Cada mosaico dice, de arriba abajo, su clave y el icono del estado
    de la parte de su departamento, el nombre, «Eleg. N · Esp. N» (con el código del departamento solo si no es el
    mayoritario de la carrera, `$majority`) y «Grupos N» en Mono de 15 px con el conteo de anomalías a la vista. Lo
    pinta el estado de esa parte (`$parts`, en `data-tone`): regresada en error con su icono, aceptada con relleno
    tenue y candado (o palomita si toda la propuesta está bloqueada, `$generalLock`), pendiente neutra y sin grupos
    punteada. Con un departamento elegido (`data-focus` en el marco) las materias de los demás (`other`) van sin
    relleno, con borde y texto tenues, sin bajar la opacidad, y el encabezado de cada semestre dice «N de SC».
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
                $figure = $department !== '' ? "{$groups} de {$department}" : $groups.' '.($groups === 1 ? 'grupo' : 'grupos');
            @endphp
            <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" :note="$department !== '' ? 'grupos de '.$department : null" wire:key="semester-{{ $semester }}">
                <x-slot:figures><span data-figure="{{ $semester }}">{{ $figure }}</span></x-slot:figures>

                @foreach ($items as $item)
                    @php
                        $requested = $item->requested_groups > 0;
                        $code = $item->subject->department->code;
                        $part = $parts[$item->subject->department_id];
                        $dimmed = $department !== '' && $code !== $department;
                        $anomalies = $overview->anomaliesFor($item);
                        $specialty = $overview->specialtyFor($item);
                        $tone = match (true) {
                            $dimmed => 'other',
                            $part === 'returned' => 'returned',
                            $part === 'accepted' => 'accepted',
                            ! $requested => 'none',
                            default => 'pending',
                        };
                        $classes = Illuminate\Support\Arr::toCssClasses([
                            match ($tone) {
                                'other' => 'border-base-300 bg-base-100 text-neutral',
                                'returned' => 'border-error bg-error/10 text-error',
                                'accepted' => 'border-base-300 bg-base-200 text-neutral',
                                'none' => 'border-base-content/30 bg-base-200 text-base-content',
                                default => 'border-base-content/30 bg-base-100 text-base-content',
                            },
                            'border-dashed' => ! $requested && $tone !== 'other',
                            'data-[found]:ring-2 data-[found]:ring-error data-[found]:ring-offset-2 data-[found]:ring-offset-base-100',
                        ]);
                        $title = implode(' · ', array_filter([isset($locks[$item->id]) ? $locks[$item->id]->message($code) : null, ...$anomalies]));
                    @endphp
                    @if ($specialty !== null && ($loop->first || $overview->specialtyFor($items[$loop->index - 1]) !== $specialty))
                        <x-core::semester-grid.separator :specialty="$specialty" :name="$specialtyNames[$specialty]" wire:key="separator-{{ $semester }}-{{ $specialty }}" />
                    @endif

                    <x-core::semester-grid.tile :row="$layout->rows[$item->subject->code]" :code="$item->subject->code" :name="$item->subject->name" soft :muted="$dimmed || $part === 'accepted' || ! $requested"
                        :class="$classes"
                        wire:click="edit('{{ $item->id }}')" wire:key="tile-{{ $item->id }}" :data-item="$item->id" :data-me="$itemId === $item->id ? '' : null"
                        :data-on-chain="$department !== '' && $code === $department ? '' : null"
                        :title="$title !== '' ? $title : null" data-opener data-testid="tile-{{ $item->subject->code }}" data-part="{{ $part }}" data-tone="{{ $tone }}">
                        <x-slot:icon>
                            @if ($code !== $majority && ! in_array($part, ['returned', 'accepted'], true))
                                <span class="font-mono text-xs font-normal" data-testid="dept-{{ $item->subject->code }}" title="{{ $item->subject->department->name }}">{{ $code }}</span>
                            @endif
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
                        <x-slot:requisites class="flex items-baseline" data-testid="figures-{{ $item->subject->code }}" title="Elegibles {{ $item->eligible_students }} · Esperados {{ $item->expected_students }}"><span class="truncate"><span class="min-[1600px]:hidden">El.</span><span class="max-[1599px]:hidden">Eleg.</span> <span class="font-mono">{{ $item->eligible_students }}</span> · <span class="min-[1600px]:hidden">Es.</span><span class="max-[1599px]:hidden">Esp.</span> <span class="font-mono">{{ $item->expected_students }}</span></span></x-slot:requisites>
                        <x-slot:start><span data-testid="groups-{{ $item->subject->code }}"><span class="font-sans text-xs font-normal">Grupos</span> <span class="text-[15px] font-semibold">{{ $item->requested_groups }}</span></span></x-slot:start>
                        <x-slot:end>
                            @if ($anomalies !== [])
                                <span class="inline-flex items-center gap-0.5 font-semibold text-warning" data-testid="anomaly-{{ $item->subject->code }}">
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
@endif
