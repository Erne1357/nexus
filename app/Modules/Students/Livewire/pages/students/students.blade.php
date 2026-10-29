{{--
    Listado de alumnos con la anatomía de una pantalla de catálogo (DESIGN.md, "Layout"): encabezado con
    búsqueda y filtros, sin acción principal porque los alumnos llegan de la importación; tabla en una
    superficie blanca con filete, el número de control en Mono y el estatus con su insignia; al pie, el rango
    y la paginación. Debajo de 768 px, renglones apilados (control, nombre y estatus; luego el detalle).
--}}
<div>
    <x-header title="Alumnos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los alumnos que llegan del SII, con su avance en la retícula. Solo lectura: se actualizan con cada importación de alumnos y de kárdex.
        </x-slot:subtitle>

        @if ($campus !== null)
            <x-slot:actions class="w-full flex-wrap md:w-auto">
                <div class="w-full md:w-64">
                    <x-input wire:model.live.debounce.300ms="search" placeholder="Número de control o nombre" aria-label="Buscar alumnos" icon="o-magnifying-glass" clearable />
                </div>
                <div class="w-[calc(50%-0.375rem)] md:w-48">
                    <x-select wire:model.live="career" aria-label="Carrera" :options="$options['careers']" placeholder="Todas las carreras" />
                </div>
                <div class="w-[calc(50%-0.375rem)] md:w-40">
                    <x-select wire:model.live="plan" aria-label="Plan" :options="$options['plans']" placeholder="Todos los planes" />
                </div>
                <div class="w-[calc(50%-0.375rem)] md:w-44">
                    <x-select wire:model.live="semester" aria-label="Semestre" :options="$options['semesters']" placeholder="Cualquier semestre" />
                </div>
                <div class="w-[calc(50%-0.375rem)] md:w-44">
                    <x-select wire:model.live="status" aria-label="Estatus" :options="$statuses" placeholder="Todos los estatus" />
                </div>
            </x-slot:actions>
        @endif
    </x-header>

    @if ($campus === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-6 py-10 text-center text-neutral" data-testid="students-no-campus">
            <x-icon name="o-building-office-2" class="mx-auto mb-2 size-8" />
            <p>Elige un campus en tu credencial para ver a sus alumnos.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            <x-table
                :headers="$headers"
                :rows="$students"
                :row-decoration="$rowDecoration"
                class="max-md:hidden [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
            >
                @scope('cell_control_number', $student)
                    <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $student->is_active])>{{ $student->control_number }}</span>
                @endscope

                @scope('cell_career', $student)
                    <span class="block max-w-52 truncate" title="{{ $student->career->name }}">
                        <span class="font-mono">{{ $student->career->code }}</span><span class="text-neutral max-[1200px]:hidden"> · {{ $student->career->name }}</span>
                    </span>
                @endscope

                @scope('cell_plan', $student)
                    <span class="whitespace-nowrap font-mono">{{ $student->studyPlan->code }}</span>
                @endscope

                @scope('cell_current_semester', $student)
                    <span class="font-mono tabular-nums">{{ $student->current_semester ?? '—' }}</span>
                @endscope

                @scope('cell_credits', $student)
                    <span class="whitespace-nowrap font-mono tabular-nums">{{ $student->progress?->credits_approved ?? '—' }}@if ($student->studyPlan->total_credits) / {{ $student->studyPlan->total_credits }}@endif</span>
                @endscope

                @scope('cell_status', $student)
                    <x-core::status-badge :tone="$student->status?->tone() ?? 'draft'" :label="$student->status?->label() ?? 'Sin estatus'" :icon="$student->status === null ? 'o-exclamation-triangle' : $student->status->icon()" />
                @endscope

                <x-slot:empty>
                    <x-icon name="o-academic-cap" label="Ningún alumno coincide con la búsqueda." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="student-cards">
                @foreach ($students as $student)
                    <li wire:key="student-card-{{ $student->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $student->is_active])>
                        <div class="flex items-center gap-2.5">
                            <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $student->is_active])>{{ $student->control_number }}</span>
                            <span class="min-w-0 flex-1 font-semibold">{{ $student->name }}</span>
                            <x-core::status-badge :tone="$student->status?->tone() ?? 'draft'" :label="$student->status?->label() ?? 'Sin estatus'" :icon="$student->status === null ? 'o-exclamation-triangle' : $student->status->icon()" />
                        </div>
                        <div class="text-sm text-neutral">
                            <span class="font-mono">{{ $student->career->code }}</span> · semestre <span class="font-mono">{{ $student->current_semester ?? '—' }}</span>
                            @if ($student->progress) · <span class="font-mono">{{ $student->progress->credits_approved }}</span> créditos @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($students->isNotEmpty() || $students->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                    @if ($students->isNotEmpty())
                        <p>Alumnos <span class="font-mono">{{ $students->first()->control_number }}</span>@if ($students->count() > 1) a <span class="font-mono">{{ $students->last()->control_number }}</span> · <span class="font-mono">{{ $students->firstItem() }}–{{ $students->lastItem() }}</span>@else · <span class="font-mono">{{ $students->firstItem() }}</span>@endif de <span class="font-mono">{{ $students->total() }}</span></p>
                    @endif
                    {{ $students->onEachSide(1)->links(data: ['scrollTo' => false]) }}
                </div>
            @endif
        </div>
    @endif
</div>
