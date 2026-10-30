{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la misma de Materias: encabezado con
    búsqueda, filtros y una sola acción principal; tabla en una superficie blanca con filete, la clave
    en Mono, el estado con su insignia y «Editar» visible y lo demás en el menú «Acciones»; al pie, el rango y la paginación.
    Entre 768 y 1199 px se ocultan la versión y los semestres; debajo de 768 px la tabla cede su lugar
    a renglones apilados (clave, nombre y estado; luego el detalle; luego las acciones), que no repiten
    los data-testid de la tabla.
--}}
<div>
    <x-header title="Planes de estudio" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los planes de cada carrera, uno por versión; sus materias por semestre están en la retícula. Un plan no se borra: se desactiva. Su carrera solo cambia mientras ningún alumno lo tenga.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por clave o nombre"
                aria-label="Buscar planes"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-select wire:model.live="careerFilter" aria-label="Carrera" :options="$careers" placeholder="Todas las carreras" />
        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Activos e inactivos" />

        <x-slot:action>
            <x-button label="Nuevo plan" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$plans"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $plan)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $plan->is_active])>{{ $plan->code }}</span>
            @endscope

            @scope('cell_career', $plan)
                <span class="font-mono" title="{{ $plan->career?->name }}">{{ $plan->career?->code }}</span>
            @endscope

            @scope('cell_version', $plan)
                <span class="font-mono">{{ $plan->version }}</span>
            @endscope

            @scope('cell_semesters_count', $plan)
                <span class="font-mono tabular-nums">{{ $plan->semesters_count ?? '—' }}</span>
            @endscope

            @scope('cell_total_credits', $plan)
                <span class="font-mono tabular-nums">{{ $plan->total_credits ?? '—' }}</span>
            @endscope

            @scope('cell_placements_count', $plan)
                <span class="whitespace-nowrap">{{ trans_choice('{0} Sin materias|{1} 1 materia|[2,*] :count materias', $plan->placements_count) }}</span>
            @endscope

            @scope('cell_is_active', $plan)
                <x-core::status-badge :tone="$plan->is_active ? 'success' : 'neutral'" :label="$plan->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $plan)
                @include('academic::plans.row-actions', ['plan' => $plan, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-rectangle-group" label="Ningún plan coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="plan-cards">
            @foreach ($plans as $plan)
                <li wire:key="plan-card-{{ $plan->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $plan->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $plan->is_active])>{{ $plan->code }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $plan->is_active])>{{ $plan->name }}</span>

                        <x-core::status-badge :tone="$plan->is_active ? 'success' : 'neutral'" :label="$plan->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    <div class="text-neutral">
                        <span class="font-mono">{{ $plan->career?->code }}</span> ·
                        <span class="font-mono">{{ $plan->version }}</span> ·
                        {{ $plan->semesters_count ?? '—' }} semestres ·
                        {{ $plan->total_credits ?? '—' }} créditos ·
                        {{ trans_choice('{0} Sin materias|{1} 1 materia|[2,*] :count materias', $plan->placements_count) }}
                    </div>

                    @include('academic::plans.row-actions', ['plan' => $plan, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($plans->isNotEmpty() || $plans->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($plans->isNotEmpty())
                    <p>Planes <span class="font-mono">{{ $plans->first()->code }}</span>@if ($plans->count() > 1) a <span class="font-mono">{{ $plans->last()->code }}</span> · <span class="font-mono">{{ $plans->firstItem() }}–{{ $plans->lastItem() }}</span>@else · <span class="font-mono">{{ $plans->firstItem() }}</span>@endif de <span class="font-mono">{{ $plans->total() }}</span></p>
                @endif

                {{ $plans->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo plan' : 'Editar plan'" box-class="max-w-2xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-input label="Clave" name="code" wire:model="code" placeholder="ISIC-2010-224" />
                </div>
                <div class="md:col-span-4">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>

                <div class="md:col-span-4">
                    <x-select label="Carrera" name="career_id" wire:model="career_id" :options="$careers" placeholder="Elige la carrera" />
                </div>
                <div class="md:col-span-2">
                    <x-input label="Versión" name="version" wire:model="version" placeholder="2010" />
                </div>

                <div class="md:col-span-2">
                    <x-input label="Semestres" name="semesters_count" wire:model="semesters_count" type="number" min="1" inputmode="numeric" />
                </div>
                <div class="md:col-span-2">
                    <x-input label="Créditos totales" name="total_credits" wire:model="total_credits" type="number" min="0" inputmode="numeric" />
                </div>
                <div class="md:col-span-2">
                    <x-input label="Vigente desde" name="valid_from" wire:model="valid_from" type="date" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
