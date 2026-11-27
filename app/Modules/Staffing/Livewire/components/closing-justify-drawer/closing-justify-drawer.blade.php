{{--
    El drawer de un grupo sin docente o sin salón (DESIGN.md, «Drawer de justificación»; `x-drawer` a la derecha,
    `max-w-md`): «Justificar A2» o «Justificación de A2», la materia y el campus; carrera y semestre, horario; lo que le
    falta con «Asignar en Asignación docente» o «Asignar salón en Horarios» (los de la página, `$parent`); el campo
    «Justificación» y quién y cuándo justificó. Al pie, fijo: «Quitar justificación», «Cancelar» y «Guardar
    justificación». Si la carga ya no se edita o el usuario no cierra la carga, el campo se deshabilita y un
    `alert-info` dice por qué. Si el grupo deja de necesitar justificación con el drawer abierto, lo dice con «Cerrar».
    `close()` es del x-drawer.
--}}
<div>
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="justify-title" data-testid="justify-drawer">
        @if ($item)
            @php($justified = $item['justification'] !== null)
            <div class="flex min-h-dvh flex-col" data-testid="justify-panel">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="justify-title" class="text-xl font-bold leading-tight">{{ $justified ? 'Justificación de' : 'Justificar' }} <span class="font-mono">{{ $item['code'] }}</span></h2>
                        <p class="mt-1 text-sm text-neutral">{{ $item['subject_name'] }} · {{ $item['campus_code'] ?? $load->modality->name }}</p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>

                <div class="flex-1 space-y-5 px-5 pt-4 pb-6">
                    <dl class="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-x-4 gap-y-3">
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carrera · semestre</dt>
                            <dd>{{ $item['career_code'] }} · Semestre {{ $item['semester'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horario</dt>
                            <dd class="font-mono text-sm">
                                @foreach (explode(' · ', $item['schedule']) as $block)
                                    <span class="block whitespace-nowrap">{{ $block }}</span>
                                @endforeach
                            </dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        @if ($item['lacks_teacher'])
                            <x-core::status-badge :tone="$justified ? 'neutral' : 'warning'" icon="o-user" label="Sin docente" />
                            <button type="button" wire:click="$parent.goTo('assign')" class="link inline-flex min-h-11 items-center gap-1 text-sm font-semibold no-underline hover:underline md:min-h-0"><x-icon name="o-user-plus" class="h-4 w-4" aria-hidden="true" />Asignar en Asignación docente</button>
                        @endif
                        @if ($item['roomless_sessions'] > 0)
                            <x-core::status-badge :tone="$justified ? 'neutral' : 'warning'" icon="o-map-pin" :label="'Sin salón · '.$item['roomless_sessions'].' '.($item['roomless_sessions'] === 1 ? 'sesión' : 'sesiones')" />
                            <button type="button" wire:click="$parent.goTo('editor')" class="link inline-flex min-h-11 items-center gap-1 text-sm font-semibold no-underline hover:underline md:min-h-0"><x-icon name="o-map-pin" class="h-4 w-4" aria-hidden="true" />Asignar salón en Horarios</button>
                        @endif
                    </div>

                    <x-textarea label="Justificación" wire:model="justification" rows="4" :disabled="! $canJustify" placeholder="Por ejemplo: Docente de nuevo ingreso, contratación en trámite." data-testid="justification-input" />

                    @if ($justified)
                        <p class="text-sm text-neutral">Justificó {{ $item['justified_by'] ?? 'un usuario ya dado de baja' }} el <span class="font-mono tabular-nums">{{ $item['justified_at']?->format('d/m/Y') }}</span> a las <span class="font-mono tabular-nums">{{ $item['justified_at']?->format('G:i') }}</span></p>
                    @endif

                    @if ($justifyReason)
                        <div role="note" class="alert alert-info alert-soft items-start text-sm" data-testid="justify-reason">
                            <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                            <p>{{ $justifyReason }}</p>
                        </div>
                    @endif
                </div>

                <footer class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
                    @if ($canJustify)
                        @if ($justified)
                            <x-button label="Quitar justificación" icon="o-x-mark" class="btn-ghost me-auto text-error max-md:h-11" wire:click="remove" spinner="remove" data-testid="remove-justification" />
                        @endif
                        <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
                        <x-button label="Guardar justificación" class="btn-primary max-md:h-11" wire:click="save" spinner="save" data-testid="save-justification" />
                    @else
                        <x-button label="Cerrar" class="btn-ghost max-md:h-11" @click="close()" />
                    @endif
                </footer>
            </div>
        @elseif ($show && $groupCode !== null)
            {{-- Con el drawer abierto, al grupo le asignaron docente y salón (o se canceló): ya no está en la lista. --}}
            <div class="flex min-h-dvh flex-col" data-testid="justify-not-needed">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <h2 id="justify-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">Grupo <span class="font-mono">{{ $groupCode }}</span></h2>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>
                <div class="flex-1 px-5 pt-4 pb-6">
                    <div role="status" class="alert alert-success alert-soft items-start text-sm">
                        <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">Este grupo ya no necesita justificación</p>
                            <p>Ya tiene docente y salón, o dejó de estar activo.</p>
                        </div>
                    </div>
                </div>
                <footer class="sticky bottom-0 flex justify-end border-t border-base-300 bg-base-100 px-5 py-3">
                    <x-button label="Cerrar" class="btn-ghost max-md:h-11" @click="close()" data-testid="close-not-needed" />
                </footer>
            </div>
        @endif
    </x-drawer>
</div>
