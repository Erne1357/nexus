{{--
    Las especialidades del plan en el panel lateral (academic::pages.plan; reglas-de-negocio.md,
    "Especialidades"): cada una con su clave, estado, nombre y cuántas materias tiene, y sus acciones
    (renombrar en su lugar, desactivar o activar, quitar). Con materias, «Quitar» se deshabilita y su motivo va
    a la vista, en el renglón de las materias, enlazado al botón con aria-describedby: un tooltip no lo ve
    quien usa teclado o pantalla táctil (S1). Debajo, el alta de una nueva. `close()` es del x-drawer; el
    título lleva el id que nombra al diálogo (aria-labelledby).
--}}
@use('App\Modules\Academic\Rules\SpecialtyRules')
<div class="flex min-h-full flex-col" data-testid="specialties-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="plan-drawer-title" class="text-xl font-bold leading-tight">Especialidades del plan</h2>
            <p class="mt-1 text-neutral">El alumno elige una. Una especialidad con materias no se quita: se desactiva.</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pt-4 pb-8">
        @if ($grid['specialties'] === [])
            <p class="text-neutral">Este plan no tiene especialidades.</p>
        @else
            <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                @foreach ($grid['specialties'] as $specialty)
                    <li class="space-y-1 px-3.5 py-3" data-specialty="{{ $specialty['code'] }}" wire:key="specialty-{{ $specialty['id'] }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-[13.5px] font-semibold">{{ $specialty['code'] }}</span>
                            <x-core::status-badge :tone="$specialty['is_active'] ? 'success' : 'neutral'" :label="$specialty['is_active'] ? 'Activa' : 'Inactiva'" />
                        </div>

                        @if ($renamingId === $specialty['id'])
                            <x-form wire:submit="saveRename" no-separator class="pt-1">
                                <x-input label="Nombre" name="renamedName" wire:model="renamedName" />

                                <x-slot:actions class="pt-1!">
                                    <x-button label="Cancelar" class="btn-ghost btn-sm" wire:click="cancelRename" />
                                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary btn-sm" spinner="saveRename" />
                                </x-slot:actions>
                            </x-form>
                        @else
                            <p @class(['text-[15px]', 'font-semibold' => $specialty['is_active'], 'text-neutral' => ! $specialty['is_active']])>{{ $specialty['name'] }}</p>
                            <p class="text-sm text-neutral" id="specialty-{{ $specialty['id'] }}-subjects">
                                {{ $specialty['subjects'] === 0 ? 'Sin materias todavía' : SpecialtyRules::hasSubjects($specialty['subjects']) }}
                            </p>

                            <div class="-ms-3 flex flex-wrap gap-0.5">
                                <x-button label="Renombrar" icon="o-pencil" class="btn-ghost btn-sm" wire:click="startRename('{{ $specialty['id'] }}')" spinner="startRename('{{ $specialty['id'] }}')" data-testid="rename-{{ $specialty['code'] }}" />
                                <x-button
                                    :label="$specialty['is_active'] ? 'Desactivar' : 'Activar'"
                                    :icon="$specialty['is_active'] ? 'o-minus-circle' : 'o-check-circle'"
                                    class="btn-ghost btn-sm"
                                    wire:click="toggleSpecialty('{{ $specialty['id'] }}')"
                                    spinner="toggleSpecialty('{{ $specialty['id'] }}')"
                                />
                                @if ($specialty['subjects'] > 0)
                                    <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm" disabled aria-describedby="specialty-{{ $specialty['id'] }}-subjects" />
                                @else
                                    <x-button
                                        label="Quitar"
                                        icon="o-trash"
                                        class="btn-ghost btn-sm text-error"
                                        wire:click="removeSpecialty('{{ $specialty['id'] }}')"
                                        wire:confirm="¿Quitar la especialidad {{ $specialty['code'] }} del plan? Se quita solo la que se capturó por error."
                                        spinner="removeSpecialty('{{ $specialty['id'] }}')"
                                    />
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <section class="space-y-3">
            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Nueva especialidad</h3>
            <x-form wire:submit="createSpecialty" no-separator>
                <x-input label="Clave" name="code" wire:model="code" class="font-mono" hint="No se repite dentro del plan." />
                <x-input label="Nombre" name="name" wire:model="name" />

                <x-slot:actions class="pt-1!">
                    <x-button label="Crear especialidad" type="submit" icon="o-plus" class="btn-primary max-md:w-full" spinner="createSpecialty" />
                </x-slot:actions>
            </x-form>
        </section>
    </div>
</div>
