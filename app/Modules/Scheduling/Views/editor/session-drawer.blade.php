{{--
    El cajón de una sesión (`x-drawer` a la derecha, 448 px; a pantalla completa en celular): su grupo y materia, día,
    inicio y fin (en los bloques del perfil del campus) y tipo; «Guardar» la mueve (UpdateGroupSession) y «Quitar
    sesión» la quita completa, con confirmación (RemoveGroupSession). El salón actual o «Sin salón»; quien no asigna
    salón ve quién lo asigna. Los mensajes del validador de esa sesión (duros en `error`) y los avisos del grupo
    (HC-12, «faltan horas»), o «Sin choques en esta sesión». Si se le quitó el salón al moverla, lo dice. Quien no
    acomoda el grupo lo ve todo deshabilitado, con el motivo una vez arriba.
--}}
<x-drawer wire:model.live="showSession" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="session-drawer-title" data-testid="session-drawer">
    @if ($session)
        @php
            $group = $session['group'];
            $readonly = $session['editReason'] !== null;
            $label = $group['subject']['code'].' · '.$group['code'];
            $kinds = [['teoria', 'Teoría', 'o-book-open'], ['practica', 'Práctica', 'o-beaker'], ['virtual', 'Virtual', 'o-computer-desktop']];
        @endphp
        <div class="flex min-h-dvh flex-col">
            <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                <div class="min-w-0 flex-1">
                    <h2 id="session-drawer-title" class="text-xl leading-tight font-bold">Sesión de {{ $label }}</h2>
                    <p class="mt-1 text-neutral">{{ $group['subject']['name'] }} · {{ $group['teacher'] ?? 'Sin docente' }}</p>
                    <x-button label="Ver el grupo" icon="o-information-circle" class="btn-ghost btn-sm -ms-3 mt-1" wire:click="openGroup('{{ $group['id'] }}')" />
                </div>
                <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar" />
            </header>

            <div class="flex-1 space-y-5 px-5 pt-4 pb-6">
                @if ($readonly)
                    <div role="note" class="alert alert-soft flex items-start gap-2 text-sm" data-testid="session-reason">
                        <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $session['editReason'] }}
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

                <div class="grid grid-cols-[1.4fr_1fr_1fr] gap-2.5 max-md:grid-cols-2">
                    <div class="max-md:col-span-2">
                        <x-select label="Día" wire:model="sessionDay" :options="$session['days']" :disabled="$readonly" />
                    </div>
                    <x-select label="Inicio" wire:model="sessionStart" :options="$session['starts']" class="font-mono" :disabled="$readonly" />
                    <x-select label="Fin" wire:model="sessionEnd" :options="$session['ends']" class="font-mono" :disabled="$readonly" />
                </div>

                <fieldset class="fieldset py-0">
                    <legend class="fieldset-legend mb-0.5">Tipo</legend>
                    <div class="join" role="group" aria-label="Tipo">
                        @foreach ($kinds as [$value, $kindLabel, $icon])
                            <button type="button" wire:click="$set('sessionKind', '{{ $value }}')" aria-pressed="{{ $sessionKind === $value ? 'true' : 'false' }}" @disabled($readonly)
                                class="btn join-item h-10 gap-2 border-base-300 font-medium shadow-none aria-pressed:btn-primary not-aria-pressed:bg-base-100">
                                <x-icon :name="$icon" class="h-4 w-4" aria-hidden="true" />{{ $kindLabel }}
                            </button>
                        @endforeach
                    </div>
                    @error('sessionKind') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>

                <section class="grid gap-1.5" aria-labelledby="session-room-title">
                    <h3 id="session-room-title" class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Salón</h3>
                    @if ($session['session']['room'])
                        <p class="font-mono text-[15px] font-semibold">{{ $session['session']['room']['code'] }}</p>
                    @else
                        <p class="flex items-center gap-1.5 font-medium text-warning"><x-icon name="o-exclamation-triangle" class="h-4 w-4" aria-hidden="true" />Sin salón</p>
                    @endif
                    @if ($session['roomReason'])
                        <p class="flex items-center gap-1.5 text-sm text-neutral"><x-icon name="o-lock-closed" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ $session['roomReason'] }}</p>
                    @endif
                </section>

                <section class="grid gap-2" aria-labelledby="session-checks-title">
                    <h3 id="session-checks-title" class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Validador</h3>
                    @foreach ($session['hard'] as $violation)
                        <p class="flex items-start gap-2 rounded-field border border-error/40 bg-error/10 px-3 py-2 leading-snug">
                            <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-error" aria-hidden="true" /><span><b class="font-mono text-error">{{ $violation->rule }}</b> · {{ $violation->message }}</span>
                        </p>
                    @endforeach
                    @foreach ($session['soft'] as $violation)
                        <p class="flex items-start gap-2 rounded-field border border-warning/40 bg-warning/10 px-3 py-2 leading-snug">
                            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" /><span><b class="font-mono text-warning">{{ $violation->rule }}</b>{{ $violation->rule === 'HC-12' ? ' · faltan horas' : '' }} · {{ $violation->message }}</span>
                        </p>
                    @endforeach
                    @if ($session['hard'] === [] && $session['soft'] === [])
                        <p class="flex items-center gap-2 rounded-field border border-success/40 bg-success/10 px-3 py-2"><x-icon name="o-check-circle" class="h-4 w-4 shrink-0 text-success" aria-hidden="true" />Sin choques en esta sesión.</p>
                    @endif
                </section>
            </div>

            <footer class="sticky bottom-0 flex items-center justify-between gap-2 border-t border-base-300 bg-base-100 px-5 py-3.5">
                <x-button label="Quitar sesión" icon="o-trash" class="btn-ghost text-error" wire:click="removeSession" spinner="removeSession" :disabled="$readonly" data-testid="remove-session"
                    wire:confirm="¿Quitar la sesión de {{ $label }} del {{ \App\Modules\Scheduling\Validation\Minutes::dayName($session['session']['day']) }} de {{ \App\Modules\Scheduling\Support\WeekGrid::range($session['session']['starts_at'], $session['session']['ends_at']) }}? Se quita completa." />
                <x-button label="Guardar" icon="o-check" class="btn-primary" wire:click="saveSession" spinner="saveSession" :disabled="$readonly" data-testid="save-session" />
            </footer>
        </div>
    @endif
</x-drawer>
