{{--
    Ficha del docente (DESIGN.md, "Ficha del docente"), con la forma de la ficha del alumno: un enlace de regreso a
    Docentes, el encabezado con su nombre, número y estado, sus datos clave y a la derecha «Editar datos» y
    «Acciones» (baja o reactivación y «Restablecer contraseña», deshabilitada con su motivo para quien no es
    administrador); una franja de cifras (horas base del periodo activo, plazas que cuentan y adscripciones) y cinco
    pestañas (`tabs tabs-border`): Datos, Plazas, Disponibilidad, Perfil e Historial. Las tres últimas muestran su
    lugar hasta que las tareas 1.14 y 1.15 las llenen. Agregar o editar una plaza y quitar una adscripción o una plaza
    se hacen en un modal. Los renglones de adscripciones y plazas son una cuadrícula que se apila debajo de 768 px.
--}}
<div class="space-y-5">
    <a href="{{ route('faculty.teachers') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="w-4 h-4" />Docentes
    </a>

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
                {{ $record->teacher->name }}
                <span class="font-mono text-xl font-semibold text-neutral tabular-nums">{{ $record->teacher->employee_number }}</span>
                <x-core::status-badge :tone="$record->teacher->is_active ? 'success' : 'neutral'" :label="$record->teacher->is_active ? 'Activo' : 'Inactivo'" />
            </h1>
            <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                @php($primary = $record->adscriptions->first())
                <span>Adscripción principal <span class="font-medium text-base-content">@if ($primary)<span class="font-mono">{{ $primary->department?->code }}</span> en <span class="font-mono">{{ $primary->campus?->code }}</span>@else — @endif</span></span>
                @if ($record->adscriptions->count() > 1)
                    <span>y <span class="font-medium text-base-content">{{ $record->adscriptions->count() - 1 }} {{ $record->adscriptions->count() === 2 ? 'otra' : 'otras' }}</span></span>
                @endif
                <span>Grado <span class="font-medium text-base-content">{{ $record->teacher->degree?->label() ?? '—' }}</span></span>
                <span>Ingreso <span class="font-medium text-base-content">{{ $record->teacher->hired_on?->format('d/m/Y') ?? '—' }}</span></span>
            </p>
        </div>

        <div class="flex gap-2 max-md:w-full">
            <x-button label="Editar datos" icon="o-pencil-square" class="btn-outline" wire:click="editData" spinner="editData" data-testid="edit-data" />

            <x-core::row-menu :name="$record->teacher->employee_number">
                <x-core::row-menu-item
                    :label="$record->teacher->is_active ? 'Desactivar' : 'Activar'"
                    wire:click="toggleActive"
                    wire:confirm="{{ $record->teacher->is_active ? '¿Dar de baja al docente '.$record->teacher->employee_number.'? Se desactiva su usuario y terminan sus jefaturas vigentes.' : '¿Activar al docente '.$record->teacher->employee_number.'? Su usuario vuelve a estar activo.' }}"
                    spinner="toggleActive"
                    data-testid="toggle-active"
                />
                @if ($canReset)
                    <x-core::row-menu-item
                        label="Restablecer contraseña"
                        wire:click="resetPassword"
                        wire:confirm="¿Restablecer la contraseña de {{ $record->teacher->employee_number }}? Vuelve a la contraseña por defecto y tendrá que cambiarla al entrar."
                        spinner="resetPassword"
                        data-testid="reset-password"
                    />
                @else
                    <x-core::row-menu-item label="Restablecer contraseña" reason="Solo el administrador restablece contraseñas." disabled data-testid="reset-password" />
                @endif
            </x-core::row-menu>
        </div>
    </div>

    <div class="grid grid-cols-1 overflow-hidden rounded-box border border-base-300 bg-base-100 min-[560px]:grid-cols-3" data-testid="teacher-figures">
        <div class="flex flex-col gap-1.5 px-5 py-3.5">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horas base{{ $record->term ? ' · '.$record->term->name : '' }}</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $record->baseHours ?? '—' }}<span class="text-base font-normal text-neutral"> h</span></span>
        </div>
        <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 max-[559px]:border-t min-[560px]:border-l">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Plazas que cuentan</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ count(array_filter($record->countsInTerm)) }}<span class="text-base font-normal text-neutral"> de {{ $record->positions->count() }}</span></span>
        </div>
        <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 max-[559px]:border-t min-[560px]:border-l">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Adscripciones</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $record->adscriptions->count() }}</span>
        </div>
    </div>

    <div role="tablist" aria-label="Secciones de la ficha" class="tabs tabs-border overflow-x-auto">
        @foreach ($tabs as $key => [$label, $count])
            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-testid="tab-{{ $key }}"
                wire:click="$set('tab', '{{ $key }}')" @class(['tab shrink-0 gap-2', 'tab-active font-bold' => $tab === $key])>
                {{ $label }}@if ($count !== null)<span class="badge badge-neutral badge-soft badge-sm font-mono">{{ $count }}</span>@endif
            </button>
        @endforeach
    </div>

    <div id="panel" role="tabpanel" aria-labelledby="tab-{{ $tab }}" class="space-y-5">
        @if ($tab === 'plazas')
            <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-plazas" data-testid="positions">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                    <h2 id="h-plazas" class="text-lg font-bold">Plazas <span class="font-mono text-neutral">{{ $record->positions->count() }}</span></h2>
                    <x-button label="Agregar plaza" icon="o-plus" class="btn-outline btn-sm" wire:click="createPosition" spinner="createPosition" data-testid="add-position" />
                </header>

                @forelse ($record->positions as $position)
                    @php($status = $record->positionStatus[$position->id])
                    <div wire:key="position-{{ $position->id }}" @class(['grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 md:grid-cols-[minmax(0,1fr)_5rem_8rem_8rem_9rem_auto]', 'text-neutral' => $status === 'vencida'])>
                        <span @class(['font-semibold', 'font-normal' => $status === 'vencida'])>{{ $position->kind->label() }}</span>
                        <span class="font-mono tabular-nums md:text-right"><span class="font-semibold">{{ $position->base_hours }}</span> h</span>
                        <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Desde </span>{{ $position->valid_from?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                        <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Hasta </span>{{ $position->valid_to?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                        <span class="max-md:col-span-2">
                            <x-core::status-badge
                                :tone="['vigente' => 'success', 'por_empezar' => 'info', 'vencida' => 'neutral'][$status]"
                                :label="['vigente' => 'Vigente', 'por_empezar' => 'Por empezar', 'vencida' => 'Vencida'][$status]"
                            />
                        </span>
                        <span class="flex justify-end gap-1 max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                            <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="editPosition('{{ $position->id }}')" spinner="editPosition('{{ $position->id }}')" :data-testid="'edit-position-'.$loop->index" aria-label="Editar la plaza {{ $position->kind->label() }}" />
                            <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="confirmRemovePosition('{{ $position->id }}')" spinner="confirmRemovePosition('{{ $position->id }}')" :data-testid="'remove-position-'.$loop->index" aria-label="Quitar la plaza {{ $position->kind->label() }}" />
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-neutral" data-testid="positions-empty">No tiene plazas: sin ellas no tiene horas base.</p>
                @endforelse

                <footer class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm text-neutral">
                    @if ($record->term)
                        <span>Horas base en {{ $record->term->name }}: <span class="font-mono font-semibold text-base-content">{{ $record->baseHours }} h</span></span>
                        <span><span class="font-mono">{{ count(array_filter($record->countsInTerm)) }}</span> {{ count(array_filter($record->countsInTerm)) === 1 ? 'plaza vigente' : 'plazas vigentes' }} el {{ $record->term->starts_on->format('d/m/Y') }}</span>
                    @else
                        <span>Sin periodo activo no hay horas base.</span>
                    @endif
                </footer>
            </section>
        @elseif (in_array($tab, ['disponibilidad', 'perfil', 'historial'], true))
            <div class="flex flex-col items-center gap-2 rounded-box border border-dashed border-base-300 bg-base-100 px-5 py-10 text-center" data-testid="tab-pending">
                <x-icon name="o-clock" class="h-6 w-6 text-neutral" aria-hidden="true" />
                <p class="font-semibold">{{ $tabs[$tab][0] }}</p>
                <p class="max-w-prose text-neutral">
                    @if ($tab === 'disponibilidad')
                        Aquí irán el resumen de su disponibilidad del periodo y el enlace al editor. Todavía no está disponible.
                    @elseif ($tab === 'perfil')
                        Aquí irán las materias que puede dar y su nivel. Todavía no está disponible.
                    @else
                        Aquí irán los grupos que ha dado y sus evaluaciones, del periodo más antiguo al más reciente. Todavía no está disponible.
                    @endif
                </p>
            </div>
        @else
            <div class="grid items-start gap-5 desktop:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-datos">
                    <header class="flex items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                        <h2 id="h-datos" class="text-lg font-bold">Datos</h2>
                        <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="editData" spinner="editData" />
                    </header>
                    <dl class="divide-y divide-base-300">
                        @foreach ([
                            ['Número de empleado', '<span class="font-mono">'.e($record->teacher->employee_number).'</span>'],
                            ['Nombre', e($record->teacher->name)],
                            ['Correo', $record->teacher->email ? e($record->teacher->email) : '<span class="text-neutral">Sin correo</span>'],
                            ['Grado', $record->teacher->degree ? e($record->teacher->degree->label()) : '<span class="text-neutral">Sin grado</span>'],
                            ['Fecha de ingreso', $record->teacher->hired_on ? e($record->teacher->hired_on->format('d/m/Y')) : '<span class="text-neutral">Sin fecha</span>'],
                        ] as [$term, $value])
                            <div class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 px-5 py-2.5">
                                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $term }}</dt>
                                <dd class="min-w-0 break-words">{!! $value !!}</dd>
                            </div>
                        @endforeach
                        <div class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] items-center gap-x-4 px-5 py-2.5">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Usuario</dt>
                            <dd class="flex flex-wrap items-center gap-2">
                                @if ($record->user)
                                    <span class="font-mono">{{ $record->user->username }}</span>
                                    <x-core::status-badge :tone="$record->user->is_active ? 'success' : 'neutral'" :label="$record->user->is_active ? 'Activo' : 'Inactivo'" />
                                    @if ($record->user->must_change_password)
                                        <x-core::status-badge tone="warning" label="Debe cambiar la contraseña" />
                                    @endif
                                @else
                                    <span class="text-neutral">Sin usuario</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-ads" data-testid="adscriptions">
                    <header class="flex items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                        <h2 id="h-ads" class="text-lg font-bold">Adscripciones <span class="font-mono text-neutral">{{ $record->adscriptions->count() }}</span></h2>
                    </header>

                    @foreach ($record->adscriptions as $adscription)
                        <div wire:key="adscription-{{ $adscription->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 last:border-b-0">
                            <div class="min-w-0">
                                <span class="font-semibold">{{ $adscription->department?->name }}</span>
                                <span class="block text-neutral"><span class="font-mono">{{ $adscription->campus?->code }}</span> · {{ $adscription->campus?->name }}</span>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-1 max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                                @if ($adscription->is_primary)
                                    <x-core::status-badge tone="primary" label="Principal" class="me-2" />
                                @else
                                    <x-button label="Hacer principal" class="btn-ghost btn-sm" wire:click="setPrimary('{{ $adscription->id }}')" spinner="setPrimary('{{ $adscription->id }}')" :data-testid="'set-primary-'.$adscription->id" />
                                @endif

                                @if ($removal[$adscription->id] === null)
                                    <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="confirmRemoveAdscription('{{ $adscription->id }}')" spinner="confirmRemoveAdscription('{{ $adscription->id }}')" :data-testid="'remove-adscription-'.$adscription->id" aria-label="Quitar la adscripción a {{ $adscription->department?->name }} en {{ $adscription->campus?->name }}" />
                                @else
                                    <span class="tooltip tooltip-left" data-tip="{{ $removal[$adscription->id] }}">
                                        <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm" disabled :data-testid="'remove-adscription-'.$adscription->id" aria-label="Quitar la adscripción a {{ $adscription->department?->name }} en {{ $adscription->campus?->name }}: {{ $removal[$adscription->id] }}" />
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            </div>
        @endif
    </div>

    <x-modal wire:model="showDataModal" title="Editar datos" box-class="max-w-2xl" separator>
        <x-form wire:submit="saveData" no-separator>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-input label="Número de empleado" value="{{ $record->teacher->employee_number }}" disabled hint="No se cambia: es su usuario." />
                </div>
                <div class="md:col-span-4">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>
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

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showDataModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="saveData" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showPositionModal" :title="$editingPositionId === null ? 'Agregar plaza' : 'Editar plaza'" :subtitle="$record->teacher->name" box-class="max-w-2xl" separator>
        <x-form wire:submit="savePosition" no-separator>
            <div class="grid gap-4 md:grid-cols-2">
                <x-select label="Tipo de plaza" name="kind" wire:model.live="kind" :options="$kinds" />
                <x-input
                    label="Horas base"
                    name="base_hours"
                    wire:model="base_hours"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="40"
                    class="font-mono"
                    :readonly="\App\Modules\Faculty\Enums\PositionKind::tryFrom($kind)?->fixedHours() !== null"
                    :hint="\App\Modules\Faculty\Enums\PositionKind::tryFrom($kind)?->fixedHours() !== null ? 'Las fija el tipo de plaza.' : 'De 1 a 40.'"
                />
                <x-input label="Desde" name="valid_from" wire:model.live="valid_from" type="date" hint="Vacío es sin fecha de inicio." />
                <x-input label="Hasta (si tiene)" name="valid_to" wire:model.live="valid_to" type="date" hint="Vacío es sin fecha de fin." />
            </div>

            @if ($positionCounts !== false && $positionCounts !== null && $record->term)
                <p @class(['flex items-center gap-1.5 text-sm', 'text-success' => $positionCounts, 'text-neutral' => ! $positionCounts]) data-testid="position-counts">
                    <x-icon :name="$positionCounts ? 'o-check-circle' : 'o-minus-circle'" class="h-4 w-4" aria-hidden="true" />
                    {{ $positionCounts ? 'Cuenta en '.$record->term->name : 'No cuenta en '.$record->term->name }}
                </p>
            @endif

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showPositionModal = false" />
                <x-button label="Guardar plaza" type="submit" icon="o-check" class="btn-primary" spinner="savePosition" data-testid="save-position" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showRemoveAdscriptionModal" title="Quitar adscripción" :subtitle="$removing ? ($removing->department?->name.' · '.$removing->campus?->name) : null" box-class="max-w-lg" separator>
        @if ($removing)
            <p>¿Quitar la adscripción de <b>{{ $record->teacher->name }}</b> a <b>{{ $removing->department?->name }}</b> en <b>{{ $removing->campus?->name }}</b>?</p>
            <ul class="mt-3 list-disc space-y-1 ps-5 text-neutral">
                @foreach ($removingConsequences as $consequence)
                    <li>{{ $consequence }}</li>
                @endforeach
            </ul>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showRemoveAdscriptionModal = false" />
            <x-button label="Quitar adscripción" icon="o-trash" class="btn-error" wire:click="removeAdscription" spinner="removeAdscription" data-testid="confirm-remove-adscription" />
        </x-slot:actions>
    </x-modal>

    <x-modal wire:model="showRemovePositionModal" title="Quitar plaza" box-class="max-w-lg" separator>
        @if ($removingPosition)
            <p>¿Quitar la plaza de <b>{{ $removingPosition->kind->label() }}</b> de {{ $removingPosition->base_hours }} h de <b>{{ $record->teacher->name }}</b>? Dejan de contar sus horas base. Queda en la bitácora.</p>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showRemovePositionModal = false" />
            <x-button label="Quitar plaza" icon="o-trash" class="btn-error" wire:click="removePosition" spinner="removePosition" data-testid="confirm-remove-position" />
        </x-slot:actions>
    </x-modal>
</div>
