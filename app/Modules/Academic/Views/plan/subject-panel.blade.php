{{--
    El panel de una materia de la retícula (academic::pages.plan), dentro del x-drawer: su clave, nombre,
    horas y créditos y departamento; y tres secciones, cada una con su data-testid para que las tareas que
    siguen las llenen sin tocar las demás:

    - «Lugar en la retícula» (placement-section): semestre, especialidad y si es obligatoria. La tarea 1.8.9
      lo vuelve formulario (semestre, especialidad, obligatoria y «Guardar lugar») y agrega al pie «Quitar de la
      retícula…».
    - «Pide» (requires-section): cada requisito con su tipo. La tarea 1.8.10 vuelve editable el tipo, agrega
      quitar y, debajo, «Agregar requisito» con el rechazo de un ciclo.
    - «La piden» (required-by-section): solo lectura.

    `$row` es la fila de PlanGridEditorQuery; `close()` es del x-drawer; el título lleva el id que nombra al
    diálogo (aria-labelledby).
--}}
<div class="flex min-h-full flex-col" data-testid="subject-panel">
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
            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-5 gap-y-1.5">
                <dt class="text-neutral">Semestre</dt>
                <dd class="font-mono font-medium">{{ $row['semester'] }}</dd>
                <dt class="text-neutral">Especialidad</dt>
                <dd class="font-medium">{{ $row['specialty_id'] === null ? 'Ninguna (tronco común)' : ($specialtiesById[$row['specialty_id']]['name'] ?? 'Quitada') }}</dd>
            </dl>
            <p class="font-medium">{{ $row['is_required'] ? 'Obligatoria' : 'Optativa' }}</p>
        </section>

        <section class="space-y-2.5" data-testid="requires-section">
            <h3 class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Pide <span class="font-mono tracking-normal text-base-content">{{ count($row['requires']) }}</span></h3>
            @include('academic::plan.requisites', ['requisites' => $row['requires'], 'empty' => 'No pide ninguna materia.'])
        </section>

        <section class="space-y-2.5" data-testid="required-by-section">
            <h3 class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">La piden <span class="font-mono tracking-normal text-base-content">{{ count($row['required_by']) }}</span></h3>
            @include('academic::plan.requisites', ['requisites' => $row['required_by'], 'empty' => 'Ninguna materia la pide.'])
        </section>
    </div>
</div>
