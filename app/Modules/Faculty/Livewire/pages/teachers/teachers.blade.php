{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la de Campus con los filtros de Materias: encabezado
    y, en su renglón, la barra de herramientas común (búsqueda, filtros de departamento, tipo de plaza y estado, y a
    la derecha la acción principal con, a su lado, «Más acciones», que lleva «Dar de alta usuarios»: del admin, y a
    los demás deshabilitada con su motivo). Tabla en una superficie blanca con filete: el número en Mono, el
    departamento principal y debajo los otros, las horas base del periodo activo en Mono, si tiene usuario y el
    estado con su insignia; «Editar» visible y lo demás en «Acciones». Entre 768 y 1199 px se ocultan el usuario y,
    debajo de 1024, la adscripción; debajo de 768 px la tabla cede su lugar a renglones apilados (número, nombre y
    estado; luego el detalle; luego las acciones), que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Docentes" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            @if ($term === null)
                Los docentes de tu alcance con sus adscripciones y si tienen usuario; sin periodo activo no hay horas base. Un docente no se borra: se da de baja, y su usuario con él.
            @else
                Los docentes de tu alcance con sus adscripciones, sus horas base de {{ $term->name }} y si tienen usuario. Un docente no se borra: se da de baja, y su usuario con él.
            @endif
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por número o nombre"
                aria-label="Buscar docentes"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-select wire:model.live="departmentFilter" aria-label="Departamento" :options="$options['departments']" placeholder="Todos los departamentos" />
        <x-select wire:model.live="kindFilter" aria-label="Tipo de plaza" :options="$kinds" placeholder="Todas las plazas" />
        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Activos e inactivos" />

        <x-slot:action>
            <div class="flex gap-2 [&>.btn-primary]:flex-1 md:[&>.btn-primary]:flex-none">
                <x-button label="Nuevo docente" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />

                <x-core::row-menu icon="o-ellipsis-vertical" label="Más acciones" class="shrink-0">
                    @if ($canProvision)
                        <x-core::row-menu-item
                            label="Dar de alta usuarios"
                            wire:click="provisionUsers"
                            wire:confirm="¿Dar de alta usuarios? Se revisa el acceso de todos los docentes: los activos sin usuario lo reciben con la contraseña por defecto y los dados de baja lo pierden. Corre en segundo plano."
                            spinner="provisionUsers"
                        />
                    @else
                        <x-core::row-menu-item label="Dar de alta usuarios" reason="Solo el administrador da de alta usuarios." disabled />
                    @endif
                </x-core::row-menu>
            </div>
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$teachers"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_employee_number', $teacher)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $teacher->is_active])>{{ $teacher->employee_number }}</span>
            @endscope

            @scope('cell_adscriptions', $teacher)
                @include('faculty::teachers.adscriptions', ['teacher' => $teacher, 'stacked' => true])
            @endscope

            @scope('cell_base_hours', $teacher, $hours)
                @include('faculty::teachers.base-hours', ['hours' => $hours[$teacher->id] ?? null])
            @endscope

            @scope('cell_has_user', $teacher)
                {{ $teacher->user_id === null ? 'No' : 'Sí' }}
            @endscope

            @scope('cell_is_active', $teacher)
                <x-core::status-badge :tone="$teacher->is_active ? 'success' : 'neutral'" :label="$teacher->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $teacher, $rowActions)
                @include('faculty::teachers.row-actions', ['teacher' => $teacher, 'actions' => $rowActions[$teacher->id], 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-users" label="Ningún docente coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="teacher-cards">
            @foreach ($teachers as $teacher)
                <li wire:key="teacher-card-{{ $teacher->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $teacher->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $teacher->is_active])>{{ $teacher->employee_number }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $teacher->is_active])>{{ $teacher->name }}</span>

                        <x-core::status-badge :tone="$teacher->is_active ? 'success' : 'neutral'" :label="$teacher->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    <div class="text-neutral">
                        @include('faculty::teachers.adscriptions', ['teacher' => $teacher, 'stacked' => false])
                        · @include('faculty::teachers.base-hours', ['hours' => $hours[$teacher->id] ?? null]) horas base
                        · {{ $teacher->user_id === null ? 'Sin usuario' : 'Con usuario' }}
                    </div>

                    @include('faculty::teachers.row-actions', ['teacher' => $teacher, 'actions' => $rowActions[$teacher->id], 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($teachers->isNotEmpty() || $teachers->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($teachers->isNotEmpty())
                    <p>Docentes <span class="font-mono">{{ $teachers->first()->employee_number }}</span>@if ($teachers->count() > 1) a <span class="font-mono">{{ $teachers->last()->employee_number }}</span> · <span class="font-mono">{{ $teachers->firstItem() }}–{{ $teachers->lastItem() }}</span>@else · <span class="font-mono">{{ $teachers->firstItem() }}</span>@endif de <span class="font-mono">{{ $teachers->total() }}</span></p>
                @endif

                {{ $teachers->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo docente' : 'Editar docente'" box-class="max-w-2xl" separator>
        <x-form wire:submit="save" no-separator>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-input
                        label="Número de empleado"
                        name="employee_number"
                        wire:model.live.blur="employee_number"
                        inputmode="numeric"
                        :disabled="$editingId !== null"
                        :hint="$editingId !== null ? 'No se cambia: es su usuario.' : null"
                    />
                </div>

                @if ($existingId === null)
                    <div class="md:col-span-4">
                        <x-input label="Nombre" name="name" wire:model="name" />
                    </div>
                @endif
            </div>

            @if ($existingId !== null)
                <x-alert
                    :title="'Ya existe el docente '.$existingLabel"
                    description="No se da de alta otra vez: puedes adscribirlo a este departamento sin cambiar sus datos."
                    icon="o-information-circle"
                    class="alert-info alert-soft"
                    data-testid="existing-teacher"
                />

                @error('adscription')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
            @endif

            @if ($editingId === null)
                <div class="grid gap-4 md:grid-cols-6">
                    <div class="md:col-span-4">
                        <x-select
                            label="Departamento"
                            name="department_id"
                            wire:model.live="department_id"
                            :options="$targets"
                            placeholder="Elige el departamento"
                            :disabled="count($targets) === 1"
                            :hint="count($targets) === 1 ? 'Das de alta docentes solo en este departamento.' : null"
                        />
                    </div>
                </div>
            @endif

            @if ($existingId === null)
                <div class="grid gap-4 md:grid-cols-6">
                    <div class="md:col-span-4">
                        <x-input label="Correo" name="email" wire:model="email" type="email" />
                    </div>
                    <div class="md:col-span-2">
                        <x-select label="Grado" name="degree" wire:model="degree" :options="$degrees" placeholder="Sin grado" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input label="Fecha de ingreso" name="hired_on" wire:model="hired_on" type="date" />
                    </div>
                </div>
            @endif

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />

                @if ($existingId === null)
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
                @elseif ($targetCode !== null)
                    <x-button :label="'Adscribir a '.$targetCode" icon="o-link" class="btn-primary" wire:click="adscribeExisting" spinner="adscribeExisting" />
                @else
                    <span class="tooltip tooltip-left" data-tip="Elige el departamento.">
                        <x-button label="Adscribir" icon="o-link" class="btn-primary" disabled />
                    </span>
                @endif
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
