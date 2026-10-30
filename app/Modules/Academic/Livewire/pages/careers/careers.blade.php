{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la misma de Campus y Departamentos:
    encabezado con búsqueda, filtros y una sola acción principal; tabla en una superficie blanca con
    filete, la clave en Mono, el estado con su insignia y las acciones en btn-ghost; al pie, el rango
    y la paginación. Debajo de 768 px la tabla cede su lugar a renglones apilados (clave, nombre y
    estado; luego el detalle; luego las acciones), que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Carreras" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Las carreras y los campus donde se ofrecen. La oferta es la del campus activo de tu credencial. Una carrera solo se borra si no tiene planes de estudio.
        </x-slot:subtitle>

        <x-slot:actions class="w-full flex-wrap md:w-auto">
            <div class="w-full md:w-64">
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por clave o nombre"
                    aria-label="Buscar carreras"
                    icon="o-magnifying-glass"
                    clearable
                />
            </div>

            <div class="w-full md:w-52">
                <x-select
                    wire:model.live="department"
                    aria-label="Departamento"
                    :options="$departmentOptions"
                    placeholder="Todos los departamentos"
                />
            </div>

            <div class="w-full md:w-44">
                <x-select
                    wire:model.live="status"
                    aria-label="Estado"
                    :options="$statuses"
                    placeholder="Activas e inactivas"
                />
            </div>

            <x-button label="Nueva carrera" icon="o-plus" class="btn-primary max-md:flex-1" wire:click="create" spinner="create" />
        </x-slot:actions>
    </x-header>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$careers"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $career)
                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $career->is_active])>{{ $career->code }}</span>
            @endscope

            @scope('cell_department', $career)
                <span class="font-mono tabular-nums">{{ $career->department->code }}</span>
            @endscope

            @scope('cell_study_plans_count', $career)
                <span class="font-mono tabular-nums">{{ $career->study_plans_count }}</span>
            @endscope

            @scope('cell_offered_here', $career)
                @if ($career->offered_here === null)
                    <span class="text-neutral">—</span>
                @else
                    <x-core::status-badge :tone="$career->offered_here ? 'success' : 'neutral'" :label="$career->offered_here ? 'Sí' : 'No'" />
                @endif
            @endscope

            @scope('cell_is_active', $career)
                <x-core::status-badge :tone="$career->is_active ? 'success' : 'neutral'" :label="$career->is_active ? 'Activa' : 'Inactiva'" />
            @endscope

            @scope('actions', $career, $campus)
                @include('academic::careers.row-actions', ['career' => $career, 'campus' => $campus, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-academic-cap" label="Ninguna carrera coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="career-cards">
            @foreach ($careers as $career)
                <li wire:key="career-card-{{ $career->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $career->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $career->is_active])>{{ $career->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $career->is_active])>{{ $career->name }}</span>

                        <x-core::status-badge :tone="$career->is_active ? 'success' : 'neutral'" :label="$career->is_active ? 'Activa' : 'Inactiva'" />
                    </div>

                    <div class="text-neutral">
                        {{ $career->department->code }} ·
                        {{ $career->study_plans_count }} {{ $career->study_plans_count === 1 ? 'plan' : 'planes' }}
                        @if ($campus !== null)
                            · {{ $career->offered_here ? "Se ofrece en {$campus->code}" : "No se ofrece en {$campus->code}" }}
                        @endif
                    </div>

                    @include('academic::careers.row-actions', ['career' => $career, 'campus' => $campus, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($careers->isNotEmpty() || $careers->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($careers->isNotEmpty())
                    <p>Carreras <span class="font-mono">{{ $careers->first()->code }}</span>@if ($careers->count() > 1) a <span class="font-mono">{{ $careers->last()->code }}</span> · <span class="font-mono">{{ $careers->firstItem() }}–{{ $careers->lastItem() }}</span>@else · <span class="font-mono">{{ $careers->firstItem() }}</span>@endif de <span class="font-mono">{{ $careers->total() }}</span></p>
                @endif

                {{ $careers->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nueva carrera' : 'Editar carrera'" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-3">
                <x-input label="Clave" name="code" wire:model="code" placeholder="ISC" />
                <div class="md:col-span-2">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>
            </div>

            <x-select label="Departamento" name="department_id" wire:model="department_id" :options="$departmentOptions" placeholder="Elige un departamento" />

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
