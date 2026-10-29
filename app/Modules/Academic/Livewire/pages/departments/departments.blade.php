{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la misma de Campus: encabezado con
    búsqueda, filtro y una sola acción principal; tabla en una superficie blanca con filete, la clave
    en Mono, el estado con su insignia y las acciones en btn-ghost; al pie, el rango y la paginación.
    Debajo de 768 px la tabla cede su lugar a renglones apilados (clave, nombre y estado; luego el
    detalle; luego las acciones), que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Departamentos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Las unidades académicas que agrupan carreras, materias y docentes. Un departamento solo se borra si no tiene carreras ni materias.
        </x-slot:subtitle>

        <x-slot:actions class="w-full flex-wrap md:w-auto">
            <div class="w-full md:w-64">
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por clave o nombre"
                    aria-label="Buscar departamentos"
                    icon="o-magnifying-glass"
                    clearable
                />
            </div>

            <div class="flex-1 md:w-44 md:flex-none">
                <x-select
                    wire:model.live="status"
                    aria-label="Estado"
                    :options="$statuses"
                    placeholder="Activos e inactivos"
                />
            </div>

            <x-button label="Nuevo departamento" icon="o-plus" class="btn-primary max-md:flex-1" wire:click="create" spinner="create" />
        </x-slot:actions>
    </x-header>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$departments"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $department)
                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $department->is_active])>{{ $department->code }}</span>
            @endscope

            @scope('cell_careers_count', $department)
                <span class="font-mono tabular-nums">{{ $department->careers_count }}</span>
            @endscope

            @scope('cell_subjects_count', $department)
                <span class="font-mono tabular-nums">{{ $department->subjects_count }}</span>
            @endscope

            @scope('cell_is_active', $department)
                <x-core::status-badge :tone="$department->is_active ? 'success' : 'neutral'" :label="$department->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $department)
                @include('academic::departments.row-actions', ['department' => $department, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-building-library" label="Ningún departamento coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="department-cards">
            @foreach ($departments as $department)
                <li wire:key="department-card-{{ $department->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $department->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $department->is_active])>{{ $department->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $department->is_active])>{{ $department->name }}</span>

                        <x-core::status-badge :tone="$department->is_active ? 'success' : 'neutral'" :label="$department->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    <div class="text-neutral">
                        {{ $department->careers_count }} {{ $department->careers_count === 1 ? 'carrera' : 'carreras' }} ·
                        {{ $department->subjects_count }} {{ $department->subjects_count === 1 ? 'materia' : 'materias' }}
                    </div>

                    @include('academic::departments.row-actions', ['department' => $department, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($departments->isNotEmpty() || $departments->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($departments->isNotEmpty())
                    <p>Departamentos <span class="font-mono">{{ $departments->first()->code }}</span>@if ($departments->count() > 1) a <span class="font-mono">{{ $departments->last()->code }}</span> · <span class="font-mono">{{ $departments->firstItem() }}–{{ $departments->lastItem() }}</span>@else · <span class="font-mono">{{ $departments->firstItem() }}</span>@endif de <span class="font-mono">{{ $departments->total() }}</span></p>
                @endif

                {{ $departments->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo departamento' : 'Editar departamento'" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-3">
                <x-input label="Clave" name="code" wire:model="code" placeholder="SIS" />
                <div class="md:col-span-2">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
