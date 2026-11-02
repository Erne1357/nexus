{{--
    La vista «Retícula» de la propuesta (maqueta C, DESIGN.md "Retícula del alumno"): semestres en columnas con
    los mosaicos de Core (core::semester-grid), uno por materia con su clave, departamento, nombre, elegibles y
    grupos pedidos. Un mosaico sin grupos va punteado; uno bloqueado lleva candado. Con un departamento elegido
    (`data-focus` en el marco), las materias de los demás se atenúan. Tocar un mosaico abre el panel.
--}}
@if ($layout->columns === [])
    <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral" data-testid="proposal-no-items">Esta propuesta no tiene materias.</div>
@else
    <x-core::semester-grid.frame :layout="$layout" data-testid="proposal-grid" :data-focus="$department !== '' ? $department : null">
        @foreach ($overview->semesters as $semester => $items)
            <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" wire:key="semester-{{ $semester }}">
                <x-slot:figures>{{ collect($items)->sum('requested_groups') }} gr</x-slot:figures>

                @foreach ($items as $item)
                    @php($requested = $item->requested_groups > 0)
                    <x-core::semester-grid.tile :row="$layout->rows[$item->subject->code]" :code="$item->subject->code" :name="$item->subject->name" :muted="! $requested"
                        :class="$requested ? 'border-info/40 bg-info/10 text-info' : 'border-dashed border-base-content/25 bg-base-200 text-neutral'"
                        wire:click="edit('{{ $item->id }}')" wire:key="tile-{{ $item->id }}" :data-me="$itemId === $item->id ? '' : null"
                        :data-on-chain="$department !== '' && $item->subject->department->code === $department ? '' : null"
                        :title="$locks[$item->id] ?? null" data-testid="tile-{{ $item->subject->code }}">
                        <x-slot:icon>
                            @if (isset($locks[$item->id]))
                                <x-icon name="o-lock-closed" class="w-4 h-4 shrink-0 text-neutral" aria-hidden="true" />
                                <span class="sr-only">Bloqueada</span>
                            @endif
                        </x-slot:icon>
                        <x-slot:requisites>{{ $item->subject->department->code }}</x-slot:requisites>
                        <x-slot:start><span class="text-neutral" title="Elegibles">{{ $item->eligible_students }} el.</span></x-slot:start>
                        <x-slot:end><span class="font-semibold">{{ $item->requested_groups }} gr</span></x-slot:end>
                    </x-core::semester-grid.tile>
                @endforeach
            </x-core::semester-grid.semester>
        @endforeach
    </x-core::semester-grid.frame>
@endif
