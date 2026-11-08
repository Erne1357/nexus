{{--
    El cajón de una sesión (`x-drawer` a la derecha, hasta 448 px y nunca más ancho que la ventana): su grupo y materia,
    «Ver el grupo»; arriba, lo que dice el validador de la sesión (los choques de la sesión en `error` y los avisos del
    grupo, HC-12 «faltan horas», o «Sin choques en esta sesión.»); día, inicio y fin (en los bloques del perfil del
    campus) y tipo; y la sección del salón (`scheduling::components.session-room`: el salón actual, los sugeridos y
    «Asignar salón», o de solo lectura con quién lo asigna), con la llave de la sesión y de su `updated_at`. Cada cosa
    se guarda de una sola forma: «Guardar horario» al pie (deshabilitado sin cambios, con su motivo) y «Asignar salón»
    en su sección. Con el día, las horas o el tipo sin guardar, la sección del salón se cambia por su motivo («Guarda el
    horario para ver los salones libres a esa hora») y «Descartar cambios». Al pie, también «Quitar sesión» con
    confirmación. Si al moverla se le quitó el salón, lo dice. Quien no acomoda el grupo lo ve todo deshabilitado, con el
    motivo una vez arriba. Los campos van al servidor al cambiar (sin validar ni leer salones). «Ver el grupo» cambia de
    cajón sin regresar el foco (`data-drawer-switch`, de `scheduleEditor`). Los mensajes del validador llevan sus horas
    como la semana (WeekGrid::prose).
--}}
<div>
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="session-drawer-title" data-testid="session-drawer">
        @if ($show && $panel)
            @php
                $readonly = $panel['editReason'] !== null;
                $dirty = $this->dirty;
                $updatedAt = $panel['updated_at'];
                $kinds = [['teoria', 'Teoría', 'o-book-open'], ['practica', 'Práctica', 'o-beaker'], ['virtual', 'Virtual', 'o-computer-desktop']];
            @endphp
            <div class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="session-drawer-title" class="text-xl leading-tight font-bold">Sesión de {{ $panel['group']['label'] }}</h2>
                        <p class="mt-1 text-neutral">{{ $panel['group']['subject'] }} · {{ $panel['group']['teacher'] ?? 'Sin docente' }}</p>
                        <x-button label="Ver el grupo" icon-right="o-arrow-right" class="btn-ghost btn-sm -ms-3 mt-1" data-drawer-switch
                            x-on:click="close()" wire:click="$dispatchTo('scheduling::components.group-drawer', 'open-group', { id: '{{ $panel['group']['id'] }}' })" />
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar" />
                </header>

                <div class="flex-1 space-y-5 px-5 pt-4 pb-6">
                    @if ($readonly)
                        <div role="note" class="alert alert-soft flex items-start gap-2 text-sm" data-testid="session-reason">
                            <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $panel['editReason'] }}
                        </div>
                    @endif
                    @if ($roomNotice)
                        <div role="status" class="alert alert-warning alert-soft flex items-start gap-2 text-sm" data-testid="room-notice">
                            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $roomNotice }}
                        </div>
                    @endif
                    @error('session')
                        <div role="alert" class="alert alert-error alert-soft flex items-start gap-2 text-sm" data-testid="session-error">
                            <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $message }}
                        </div>
                    @enderror

                    <section class="grid gap-2" aria-labelledby="session-checks-title" data-testid="session-checks">
                        <h3 id="session-checks-title" class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Validador</h3>
                        @foreach ($panel['hard'] as $violation)
                            <p class="flex items-start gap-2 rounded-field border border-error/40 bg-error/10 px-3 py-2 leading-snug">
                                <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-error" aria-hidden="true" /><span class="min-w-0 break-words"><b class="font-mono text-error">{{ $violation['rule'] }}</b> · {{ \App\Modules\Scheduling\Support\WeekGrid::prose($violation['message']) }}</span>
                            </p>
                        @endforeach
                        @foreach ($panel['soft'] as $violation)
                            <p class="flex items-start gap-2 rounded-field border border-warning/40 bg-warning/10 px-3 py-2 leading-snug">
                                <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" /><span class="min-w-0 break-words"><b class="font-mono text-warning">{{ $violation['rule'] }}</b>{{ $violation['rule'] === 'HC-12' ? ' · faltan horas' : '' }} · {{ \App\Modules\Scheduling\Support\WeekGrid::prose($violation['message']) }}</span>
                            </p>
                        @endforeach
                        @if ($panel['hard'] === [] && $panel['soft'] === [])
                            <p class="flex items-center gap-2 rounded-field border border-success/40 bg-success/10 px-3 py-2"><x-icon name="o-check-circle" class="h-4 w-4 shrink-0 text-success" aria-hidden="true" />Sin choques en esta sesión.</p>
                        @endif
                        @if ($dirty)
                            <p class="text-sm text-neutral">Es del horario guardado; se vuelve a leer al guardar.</p>
                        @endif
                    </section>

                    <div class="grid grid-cols-[1.4fr_1fr_1fr] gap-2.5 max-md:grid-cols-2">
                        <div class="max-md:col-span-2">
                            <x-select label="Día" wire:model.live="day" :options="$panel['days']" :disabled="$readonly" />
                        </div>
                        <x-select label="Inicio" wire:model.live="startsAt" :options="$panel['starts']" class="font-mono" :disabled="$readonly" />
                        <x-select label="Fin" wire:model.live="endsAt" :options="$panel['ends']" class="font-mono" :disabled="$readonly" />
                    </div>

                    <fieldset class="fieldset py-0">
                        <legend class="fieldset-legend mb-0.5">Tipo</legend>
                        <div class="join" role="group" aria-label="Tipo" x-data="{ kind: $wire.entangle('kind').live }">
                            @foreach ($kinds as [$value, $kindLabel, $icon])
                                <button type="button" x-on:click="kind = '{{ $value }}'" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}" x-bind:aria-pressed="kind === '{{ $value }}' ? 'true' : 'false'" @disabled($readonly)
                                    class="btn join-item h-10 gap-2 border-base-300 font-medium shadow-none aria-pressed:btn-primary not-aria-pressed:bg-base-100">
                                    <x-icon :name="$icon" class="h-4 w-4" aria-hidden="true" />{{ $kindLabel }}
                                </button>
                            @endforeach
                        </div>
                        @error('kind') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
                    </fieldset>

                    @if ($dirty)
                        <section class="grid gap-2" aria-labelledby="room-gate-title" data-testid="room-gate">
                            <h3 id="room-gate-title" class="text-base font-semibold">Salón</h3>
                            <div role="note" class="grid gap-2 rounded-box border border-dashed border-base-300 bg-base-200 px-3 py-2.5">
                                <p class="flex items-start gap-2"><x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" /><span>Guarda el horario para ver los salones libres a esa hora.</span></p>
                                <p class="ps-6 text-sm text-neutral">Salón actual: @if ($panel['room'])<span class="font-mono text-base-content">{{ $panel['room'] }}</span>@else sin salón @endif</p>
                                <x-button label="Descartar cambios" icon="o-arrow-uturn-left" class="btn-ghost btn-sm justify-self-start ms-4" wire:click="discard" spinner="discard" />
                            </div>
                        </section>
                    @else
                        <livewire:scheduling::components.session-room :session-id="$sessionId" wire:key="room-{{ $sessionId }}-{{ $updatedAt }}" />
                    @endif

                </div>

                <footer class="sticky bottom-0 flex items-center justify-between gap-2 border-t border-base-300 bg-base-100 px-5 py-3.5">
                    <x-button label="Quitar sesión" icon="o-trash" class="btn-ghost text-error" wire:click="remove" spinner="remove" :disabled="$readonly" data-testid="remove-session"
                        wire:confirm="¿Quitar la sesión de {{ $panel['group']['label'] }} del {{ \App\Modules\Scheduling\Validation\Minutes::dayName($panel['day']) }} de {{ \App\Modules\Scheduling\Support\WeekGrid::range($panel['starts_at'], $panel['ends_at']) }}? Se quita completa." />
                    @if ($readonly || $dirty)
                        <x-button label="Guardar horario" icon="o-check" class="btn-primary" wire:click="save" spinner="save" :disabled="$readonly" data-testid="save-session" />
                    @else
                        <span class="tooltip tooltip-left" data-tip="Cambia el día, la hora o el tipo para guardar.">
                            <x-button label="Guardar horario" icon="o-check" class="btn-primary" disabled data-testid="save-session" />
                        </span>
                    @endif
                </footer>
            </div>
        @endif
    </x-drawer>
</div>
