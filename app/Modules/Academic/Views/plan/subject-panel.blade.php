{{--
    El panel de una materia de la retícula (academic::pages.plan), dentro del x-drawer: su clave, nombre,
    horas y créditos y departamento; y tres secciones, cada una con su data-testid para que las tareas que
    siguen las llenen sin tocar las demás:

    - «Lugar en la retícula» (placement-section): un formulario con el semestre, la especialidad («Ninguna
      (tronco común)»; una inactiva no se elige, salvo la que ya tiene) y si es obligatoria, y «Guardar lugar».
      Sus campos se llaman como las claves de UpdatePlacement, así sus errores caen en su campo.
    - «Pide» (requires-section, academic::plan.requires-editor): cada requisito con su tipo editable y quitar y,
      debajo, «Agregar requisito» con el rechazo de un ciclo y su cadena.
    - «La piden» (required-by-section): solo lectura; se edita desde el panel de la materia que la pide.

    Al pie, fijo, «Cada cambio queda en la bitácora.» y «Quitar de la retícula…», que abre la confirmación
    (academic::plan.remove-placement, en la página: un modal dentro del panel quedaría encerrado en él).

    `$row` es la fila de PlanGridEditorQuery y `$placementOptions`, las opciones de sus selects; `close()` es del
    x-drawer; el título lleva el id que nombra al diálogo (aria-labelledby).
--}}
<div class="flex min-h-dvh flex-col" data-testid="subject-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-[15px] font-semibold">{{ $row['code'] }}</span>
                @unless ($row['is_active'])
                    <x-core::status-badge tone="neutral" label="Inactiva" />
                @endunless
            </div>
            <h2 id="plan-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $row['name'] }}</h2>
            <p class="mt-1 text-neutral"><span class="font-mono">{{ $row['theory_hours'] }}-{{ $row['practice_hours'] }}-{{ $row['credits'] }}</span>@if ($row['department']) · {{ $row['department'] }}@endif</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pt-4 pb-8">
        @unless ($row['is_active'])
            <x-alert icon="o-minus-circle" class="alert-warning alert-soft text-sm" title="Inactiva en el catálogo de materias. Sigue en esta retícula hasta que la quites." />
        @endunless

        <section class="space-y-2.5" data-testid="placement-section">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Lugar en la retícula</h3>
            <x-form wire:submit="savePlacement('{{ $row['placement_id'] }}')" class="gap-2!">
                <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-3">
                    <x-select label="Semestre" name="semester" wire:model="semester" :options="$placementOptions['semesters']" class="font-mono" data-testid="placement-semester" />
                    <x-select label="Especialidad" name="specialty_id" wire:model="specialty_id" :options="$placementOptions['specialties']" placeholder="Ninguna (tronco común)" placeholder-value="" data-testid="placement-specialty" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-checkbox label="Obligatoria" name="is_required" wire:model="is_required" />
                    <x-button label="Guardar lugar" type="submit" icon="o-check" class="btn-primary btn-sm" spinner="savePlacement" />
                </div>
            </x-form>
        </section>

        @include('academic::plan.requires-editor', ['row' => $row])

        <section class="space-y-2.5" data-testid="required-by-section">
            <h3 class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">La piden <span class="font-mono tracking-normal text-base-content">{{ count($row['required_by']) }}</span></h3>
            @include('academic::plan.requisites', ['requisites' => $row['required_by'], 'empty' => 'Ninguna materia la pide.'])
        </section>
    </div>

    <footer class="sticky bottom-0 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-base-300 bg-base-100 py-2.5 ps-5 pe-3">
        <p class="text-sm text-neutral">Cada cambio queda en la bitácora.</p>
        <x-button label="Quitar de la retícula…" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="confirmRemove" spinner="confirmRemove" data-testid="remove-placement" />
    </footer>
</div>
