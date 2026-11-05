{{--
    Los campos del formulario «Nombrar» (DESIGN.md, «Personas y puestos», modal «Nombrar»), en el modal de la ficha y en
    el panel del Organigrama: aviso de `user`, «Puesto» con su nota y su pista, campus, carrera y unidad según el puesto
    (la unidad, deshabilitada si viene fija) y las fechas. Espera $offered, $roleCase, $options, $roleNote y $lockedUnit.
--}}
@php
    $unitLabel = $roleCase?->unitLabel() ?? 'Departamento académico';
    $columns = $roleCase?->scopeColumns() ?? [];
@endphp

@error('user')
    <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
@enderror

<div>
    <x-select label="Puesto" name="role" wire:model.live="role" :options="$offered" placeholder="Elige un puesto" />
    <p class="mt-1 text-sm text-neutral">{{ $roleNote }}</p>
</div>

@if ($roleCase !== null)
    <p class="text-sm" data-testid="role-hint">{{ $roleCase->appointHint() ?? '' }}</p>
@endif

@if ($columns !== [])
    <div class="grid gap-4 sm:grid-cols-2">
        @if (in_array('campus_id', $columns, true))
            <div data-testid="field-campus">
                <x-select label="Campus" name="campus" wire:model.live="campus" :options="$options['campuses']" placeholder="Elige el campus" />
            </div>
        @endif

        @if (in_array('career_id', $columns, true))
            <div data-testid="field-career">
                <x-select label="Carrera" name="career" wire:model="career" :options="$options['careers']" :placeholder="$campus === '' ? 'Elige primero el campus' : 'Elige la carrera'" />
            </div>
        @endif

        @if (in_array('org_unit_id', $columns, true))
            <div data-testid="field-unit" class="sm:col-span-2">
                @if ($lockedUnit !== null)
                    <x-input :label="$unitLabel" :value="$lockedUnit->name" disabled />
                    @error('unit')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                @else
                    <x-select :label="$unitLabel" name="unit" wire:model="unit" :options="$options['units']" placeholder="Elige" />
                @endif
            </div>
        @endif
    </div>
@endif

<div class="grid gap-4 sm:grid-cols-2">
    <x-input label="Desde" name="from" type="date" wire:model="from" />
    <x-input label="Hasta (opcional)" name="to" type="date" wire:model="to" />
</div>
