{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la misma de Departamentos: encabezado y, en su renglón, la barra de herramientas común (x-core::catalog-toolbar: búsqueda,
    filtros y una sola acción principal); tabla en una superficie blanca con filete, la
    clave en Mono, las horas y los créditos en Mono (HT-HP-créditos), el estado con su insignia y las
    acciones en btn-ghost; al pie, el rango y la paginación. Entre 768 y 1199 px se ocultan el tipo y
    el laboratorio; debajo de 768 px la tabla cede su lugar a renglones apilados (clave, nombre y
    estado; luego el detalle; luego las acciones), que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Materias" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            El catálogo de materias con sus horas y créditos; su lugar en cada plan está en la retícula. Una materia no se borra: se desactiva, y solo si ningún alumno la necesita todavía.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por clave o nombre"
                aria-label="Buscar materias"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-select wire:model.live="departmentFilter" aria-label="Departamento" :options="$departments" placeholder="Todos los departamentos" />
        <x-select wire:model.live="kindFilter" aria-label="Tipo" :options="$kindFilters" placeholder="Todos los tipos" />
        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Activas e inactivas" />

        <x-slot:action>
            <x-button label="Nueva materia" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$subjects"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $subject)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $subject->is_active])>{{ $subject->code }}</span>
            @endscope

            @scope('cell_department', $subject)
                <span class="font-mono" title="{{ $subject->department?->name }}">{{ $subject->department?->code }}</span>
            @endscope

            @scope('cell_hours', $subject)
                <span class="whitespace-nowrap font-mono tabular-nums">{{ $subject->theory_hours }}-{{ $subject->practice_hours }}-{{ $subject->credits }}</span>
            @endscope

            @scope('cell_kind', $subject)
                @if ($subject->kind === null)
                    <span class="text-neutral">Sin tipo</span>
                @else
                    {{ $subject->kind->label() }}
                @endif
            @endscope

            @scope('cell_needs_lab', $subject)
                {{ $subject->needs_lab ? 'Sí' : 'No' }}
            @endscope

            @scope('cell_study_plans_count', $subject)
                <span class="whitespace-nowrap">{{ trans_choice('{0} En ningún plan|{1} En 1 plan|[2,*] En :count planes', $subject->study_plans_count) }}</span>
            @endscope

            @scope('cell_is_active', $subject)
                <x-core::status-badge :tone="$subject->is_active ? 'success' : 'neutral'" :label="$subject->is_active ? 'Activa' : 'Inactiva'" />
            @endscope

            @scope('actions', $subject)
                @include('academic::subjects.row-actions', ['subject' => $subject, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-book-open" label="Ninguna materia coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="subject-cards">
            @foreach ($subjects as $subject)
                <li wire:key="subject-card-{{ $subject->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $subject->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $subject->is_active])>{{ $subject->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $subject->is_active])>{{ $subject->name }}</span>

                        <x-core::status-badge :tone="$subject->is_active ? 'success' : 'neutral'" :label="$subject->is_active ? 'Activa' : 'Inactiva'" />
                    </div>

                    <div class="text-neutral">
                        <span class="font-mono">{{ $subject->department?->code }}</span> ·
                        <span class="font-mono tabular-nums">{{ $subject->theory_hours }}-{{ $subject->practice_hours }}-{{ $subject->credits }}</span> ·
                        {{ $subject->kind?->label() ?? 'Sin tipo' }} ·
                        {{ trans_choice('{0} En ningún plan|{1} En 1 plan|[2,*] En :count planes', $subject->study_plans_count) }}@if ($subject->needs_lab) · Laboratorio @endif
                    </div>

                    @include('academic::subjects.row-actions', ['subject' => $subject, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($subjects->isNotEmpty() || $subjects->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($subjects->isNotEmpty())
                    <p>Materias <span class="font-mono">{{ $subjects->first()->code }}</span>@if ($subjects->count() > 1) a <span class="font-mono">{{ $subjects->last()->code }}</span> · <span class="font-mono">{{ $subjects->firstItem() }}–{{ $subjects->lastItem() }}</span>@else · <span class="font-mono">{{ $subjects->firstItem() }}</span>@endif de <span class="font-mono">{{ $subjects->total() }}</span></p>
                @endif

                {{ $subjects->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nueva materia' : 'Editar materia'" box-class="max-w-2xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-input label="Clave" name="code" wire:model="code" placeholder="ACF-0901" />
                </div>
                <div class="md:col-span-4">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>

                <div class="md:col-span-4">
                    <x-select label="Departamento" name="department_id" wire:model="department_id" :options="$departments" placeholder="Elige el departamento" />
                </div>
                <div class="md:col-span-2">
                    <x-select label="Tipo" name="kind" wire:model="kind" :options="$kinds" placeholder="Sin tipo" />
                </div>

                <div class="md:col-span-2">
                    <x-input label="Horas de teoría" name="theory_hours" wire:model="theory_hours" type="number" min="0" inputmode="numeric" />
                </div>
                <div class="md:col-span-2">
                    <x-input label="Horas de práctica" name="practice_hours" wire:model="practice_hours" type="number" min="0" inputmode="numeric" />
                </div>
                <div class="md:col-span-2">
                    <x-input label="Créditos" name="credits" wire:model="credits" type="number" min="0" inputmode="numeric" />
                </div>

                <div class="md:col-span-6">
                    <x-toggle label="Requiere laboratorio" name="needs_lab" wire:model="needs_lab" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
