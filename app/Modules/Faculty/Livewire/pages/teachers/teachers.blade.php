{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la de Campus con los filtros de Materias: encabezado
    y, en su renglón, la barra de herramientas común (búsqueda, filtros de departamento, tipo de plaza y estado, que
    empieza en «Activos», y a la derecha la acción principal con, a su lado, «Más acciones», que lleva «Dar de alta
    usuarios»: del admin, y a los demás deshabilitada con su motivo). Debajo, si hay docentes activos sin correo
    institucional, cuántos son y «Ver solo esos», el filtro que no cabe en la barra a 1440 px. Tabla en una
    superficie blanca con filete: el número en Mono, el departamento principal y debajo los otros, las horas base del
    periodo activo en Mono, el estado de su acceso (AC-04) y el estado con su insignia; «Editar» visible y lo demás en
    «Acciones». Al editar, el correo institucional va con el parcial de la ficha (AC-06). Entre 768 y 1199 px se ocultan el acceso y,
    debajo de 1024, la adscripción; debajo de 768 px la tabla cede su lugar a renglones apilados (número, nombre y
    estado; luego el detalle; luego las acciones), que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Docentes" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            @if ($term === null)
                Los docentes de tu alcance con sus adscripciones y su acceso; sin periodo activo no hay horas base. Un docente no se borra: se da de baja, y su usuario con él.
            @else
                Los docentes de tu alcance con sus adscripciones, sus horas base de {{ $term->name }} y su acceso. Un docente no se borra: se da de baja, y su usuario con él.
            @endif
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar narrow>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Número o nombre"
                aria-label="Buscar docentes"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-select wire:model.live="departmentFilter" aria-label="Departamento" :options="$options['departments']" placeholder="Departamento" />
        <x-select wire:model.live="kindFilter" aria-label="Tipo de plaza" :options="$kinds" placeholder="Tipo de plaza" />
        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Estado" />

        <x-slot:action>
            <div class="flex gap-2 [&>.btn-primary]:flex-1 md:[&>.btn-primary]:flex-none">
                <x-button label="Nuevo docente" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />

                <x-core::row-menu icon="o-ellipsis-vertical" label="Más acciones" class="shrink-0">
                    @if ($canProvision)
                        <x-core::row-menu-item
                            label="Dar de alta usuarios"
                            wire:click="provisionUsers"
                            x-confirm="Se revisa el acceso de todos los docentes: los activos sin usuario lo reciben, todavía sin acceso, y los dados de baja lo pierden. Corre en segundo plano."
                            data-confirm-title="¿Dar de alta usuarios?"
                            data-confirm-button="Dar de alta"
                            data-confirm-tone="warning"
                            spinner="provisionUsers"
                        />
                    @else
                        <x-core::row-menu-item label="Dar de alta usuarios" reason="Solo el administrador da de alta usuarios." disabled />
                    @endif
                </x-core::row-menu>
            </div>
        </x-slot:action>
    </x-core::catalog-toolbar>

    @if ($emailFilter === 'missing')
        <p class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="missing-emails">
            <x-icon name="o-funnel" class="h-4 w-4 text-neutral" aria-hidden="true" />
            <span>Solo los que no tienen correo institucional.</span>
            <button type="button" class="link link-primary font-medium" wire:click="$set('emailFilter', '')">Ver todos</button>
        </p>
    @elseif ($missingEmails > 0)
        <p class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="missing-emails">
            <x-icon name="o-envelope" class="h-4 w-4 text-neutral" aria-hidden="true" />
            <span><span class="font-mono font-semibold tabular-nums">{{ $missingEmails }}</span> {{ $missingEmails === 1 ? 'docente activo' : 'docentes activos' }} sin correo institucional: sin él no reciben su invitación de acceso.</span>
            <button type="button" class="link link-primary font-medium" wire:click="showMissingEmails">Ver solo {{ $missingEmails === 1 ? 'ese' : 'esos' }}</button>
        </p>
    @endif

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$teachers"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_rfc', $teacher)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $teacher->is_active])>{{ $teacher->rfc }}</span>
            @endscope

            @scope('cell_adscriptions', $teacher)
                @include('faculty::teachers.adscriptions', ['teacher' => $teacher, 'stacked' => true])
            @endscope

            @scope('cell_base_hours', $teacher, $hours)
                @include('faculty::teachers.base-hours', ['hours' => $hours[$teacher->id] ?? null])
            @endscope

            {{-- En texto que se parte en dos renglones y no en insignia: «Debe cambiar su contraseña» no cabe en la tabla a 1440 px. Una cuenta que el admin dio de baja en el panel lo dice (CF-09). --}}
            @scope('cell_access', $teacher, $access)
                @if ($teacher->user?->deactivated_at !== null)
                    <span class="block text-sm leading-tight text-neutral" data-testid="access-{{ $teacher->rfc }}">Cuenta dada de baja por el admin</span>
                @else
                    <span @class(['block text-sm leading-tight', 'text-neutral' => $access[$teacher->id] === \App\Modules\Core\Enums\UserAccess::None]) data-testid="access-{{ $teacher->rfc }}">{{ $access[$teacher->id]->label() }}</span>
                @endif
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
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $teacher->is_active])>{{ $teacher->rfc }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $teacher->is_active])>{{ $teacher->name }}</span>

                        <x-core::status-badge :tone="$teacher->is_active ? 'success' : 'neutral'" :label="$teacher->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    <div class="text-neutral">
                        @include('faculty::teachers.adscriptions', ['teacher' => $teacher, 'stacked' => false])
                        · @include('faculty::teachers.base-hours', ['hours' => $hours[$teacher->id] ?? null]) horas base
                        · {{ $teacher->user?->deactivated_at !== null ? 'Cuenta dada de baja por el admin' : $access[$teacher->id]->label() }}
                    </div>

                    @include('faculty::teachers.row-actions', ['teacher' => $teacher, 'actions' => $rowActions[$teacher->id], 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($teachers->isNotEmpty() || $teachers->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($teachers->isNotEmpty())
                    <p>Docentes <span class="font-mono">{{ $teachers->first()->rfc }}</span>@if ($teachers->count() > 1) a <span class="font-mono">{{ $teachers->last()->rfc }}</span> · <span class="font-mono">{{ $teachers->firstItem() }}–{{ $teachers->lastItem() }}</span>@else · <span class="font-mono">{{ $teachers->firstItem() }}</span>@endif de <span class="font-mono">{{ $teachers->total() }}</span></p>
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
                        label="RFC"
                        name="rfc"
                        wire:model.live.blur="rfc"
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

            @if ($editingId !== null)
                <div class="grid gap-4 md:grid-cols-6">
                    <div class="md:col-span-3">
                        <x-input label="Correo" name="email" wire:model="email" type="email" />
                    </div>
                    <div class="md:col-span-3">
                        @include('faculty::teachers.institutional-email-field', ['reason' => $institutionalEmailReason, 'domain' => $institutionalDomain])
                    </div>
                    <div class="md:col-span-3">
                        <x-select label="Grado" name="degree" wire:model="degree" :options="$degrees" placeholder="Sin grado" />
                    </div>
                    <div class="md:col-span-3">
                        <x-input label="Fecha de ingreso" name="hired_on" wire:model="hired_on" type="date" />
                    </div>
                </div>
            @elseif ($existingId === null)
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
