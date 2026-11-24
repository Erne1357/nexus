{{--
    El drawer de un grupo del horario de selección (maqueta B: «b-drawer», «b-mapa» y «b-asignar»; `x-drawer` a la
    derecha, `max-w-xl`). Encabezado con la clave, la materia y su horario; los datos en cuatro casillas (horas, lugares,
    espacio y días); quién lo tiene; los avisos (fuera de tu perfil, choque, solo consulta). En su turno y con el grupo
    libre: «Con este grupo llevarías…» y el salón en pestañas Lista y Mapa; al pie, «Tomar en 704». Si es suyo y está en
    su turno, «Soltar grupo». «Asignar a…» (jefatura): el docente en el orden, el salón y el motivo opcional; desde ahí,
    «Quitar docente» pide el motivo en el mismo drawer. Con la selección terminada, el docente solo consulta.
--}}
@use('App\Modules\Scheduling\Validation\Minutes')
@use('App\Modules\Staffing\Rules\LoadBand')
@php
    $spaces = [
        'aula' => ['icon' => 'o-book-open', 'label' => 'Aula'],
        'sala_computo' => ['icon' => 'o-computer-desktop', 'label' => 'Sala de cómputo'],
        'laboratorio' => ['icon' => 'o-beaker', 'label' => 'Laboratorio'],
    ];
    $dayShort = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S'];
@endphp
<div x-on:room-picked.window="$wire.chooseRoomByMapKey($event.detail.key)">
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="selection-group-title" data-testid="selection-group-drawer">
        @if ($card)
            @php
                $space = $spaces[$card['required_space']] ?? $spaces['aula'];
                $code = trim($card['subject_code'].' · '.$card['group_code'], ' ·');
                $daysText = implode(', ', array_map(fn ($day) => Minutes::dayName($day), $card['days']));
            @endphp
            <div class="flex min-h-dvh flex-col" data-testid="selection-group-panel">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="selection-group-title" class="leading-tight">
                            @if ($unassigning)
                                <span class="text-xl font-bold">Quitar docente de</span>
                            @elseif ($assigning)
                                <span class="text-xl font-bold">Asignar</span>
                            @endif
                            <span class="font-mono text-xl font-semibold">{{ $code }}</span>
                            <span class="block text-lg font-bold">{{ $card['subject_name'] }}</span>
                        </h2>
                        <p class="mt-1 text-sm text-neutral">
                            {{ $card['schedule'] }}@if ($card['semester']) · semestre {{ $card['semester'] }}@endif @if ($card['department']) · materia de {{ $card['department'] }}@endif
                        </p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>

                <div class="flex-1 space-y-5 px-5 pt-4 pb-6">
                    <dl class="grid grid-cols-2 overflow-hidden rounded-field border border-base-300 sm:grid-cols-4">
                        <div class="border-base-300 px-3 py-2.5 max-sm:border-b sm:border-e">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horas</dt>
                            <dd><span class="font-mono font-semibold">{{ $card['hours'] }}</span> a la semana</dd>
                        </div>
                        <div class="border-base-300 px-3 py-2.5 max-sm:border-b sm:border-e">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Lugares</dt>
                            <dd class="font-mono font-semibold">{{ $card['capacity'] }}</dd>
                        </div>
                        <div class="border-base-300 px-3 py-2.5 sm:border-e">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Espacio</dt>
                            <dd class="inline-flex items-center gap-1.5"><x-icon :name="$space['icon']" class="h-4 w-4" aria-hidden="true" />{{ $space['label'] }}</dd>
                        </div>
                        <div class="px-3 py-2.5">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Días</dt>
                            <dd>
                                <span class="sr-only">{{ $daysText }}</span>
                                <span class="inline-flex gap-0.5" aria-hidden="true">
                                    @foreach ($dayShort as $day => $letter)
                                        @continue($day === 6 && ! in_array(6, $card['days'], true))
                                        <span @class(['inline-grid h-[18px] min-w-[19px] place-items-center rounded-[4px] px-0.5 font-mono text-[11px] font-semibold', 'bg-secondary text-base-content' => in_array($day, $card['days'], true), 'border border-dashed border-base-300 text-neutral' => ! in_array($day, $card['days'], true)])>{{ $letter }}</span>
                                    @endforeach
                                </span>
                            </dd>
                        </div>
                    </dl>

                    @if ($card['teacher'] !== null && ! $card['mine'])
                        <p class="flex items-center gap-2 text-sm" data-testid="selection-owner">
                            <x-icon name="o-user" class="h-4 w-4 text-neutral" aria-hidden="true" />
                            <span>{{ $assigning ? 'Hoy lo tiene' : 'Lo tiene' }} <b>{{ $card['teacher'] }}</b>@if ($card['rooms'] !== []) · salón <b class="font-mono">{{ implode(', ', $card['rooms']) }}</b>@endif.@if ($assigning) Si lo asignas a otro, se le quita.@endif</span>
                        </p>
                    @endif

                    @if ($unassigning)
                        <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="selection-unassign-panel">
                            <x-icon name="o-user-minus" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                            <div>
                                <p class="font-semibold">Quitar a {{ $card['teacher'] }}</p>
                                <p>El grupo queda libre y conserva su salón.@if (! $finished) Mientras la selección siga, otro docente lo puede tomar en su turno.@endif Queda en el historial y en la bitácora con tu nombre y el motivo.</p>
                            </div>
                        </div>
                        <x-textarea label="Motivo" wire:model="unassignReason" rows="3" placeholder="Por qué se le quita el grupo" data-testid="selection-unassign-reason" />
                        @error('unassignReason')
                            <p class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
                        @enderror
                    @elseif (! $assigning)
                        @if ($card['mine'])
                            <div role="note" class="alert alert-info alert-soft items-start text-sm">
                                <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                                <div>
                                    <p class="font-semibold">Es tuyo</p>
                                    <p>
                                        @if ($card['rooms'] === [])
                                            Salón: <b>sin salón</b>; lo asigna la jefatura.
                                        @else
                                            Salón: <b class="font-mono">{{ implode(', ', $card['rooms']) }}</b>.
                                        @endif
                                        @if ($releasable)
                                            Lo puedes soltar mientras dure tu turno.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif
                        @if ($entry !== null && ! $card['in_profile'] && ($card['teacher_id'] === null || $card['mine']))
                            <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="selection-out-of-profile">
                                <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                                <div>
                                    <p class="font-semibold">Fuera de tu perfil</p>
                                    <p>No está en tu perfil ni en tu historial. Puedes tomarla; queda en tu historial.</p>
                                </div>
                            </div>
                        @endif
                        @if ($card['clashes'])
                            <div role="note" class="alert alert-error alert-soft items-start text-sm" data-testid="selection-clash">
                                <x-icon name="o-x-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                                <div>
                                    <p class="font-semibold">Choca con tu {{ $card['clash_with'] }}</p>
                                    <p>Suéltalo para tomar este.</p>
                                </div>
                            </div>
                        @endif
                        @if ($entry !== null && ! $inTurn)
                            <div role="note" class="alert alert-soft items-start text-sm" data-testid="selection-read-only">
                                <x-icon name="o-eye" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                                <div>
                                    <p class="font-semibold">Solo consulta</p>
                                    <p>
                                        @if ($finished)
                                            La selección terminó: solo tu jefe de departamento o el JPD cambian los grupos.
                                        @elseif ($entry->closed_at !== null)
                                            Ya cerraste tu carga: solo tu jefe de departamento o el JPD la cambian.
                                        @elseif ($mySlot !== null && $mySlot->isFuture())
                                            Eliges en tu turno, el {{ $mySlot->isoFormat('dddd D [de] MMMM') }} a las {{ $mySlot->format('G:i') }}.
                                        @else
                                            Eliges solo en tu turno.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif

                        @if ($pickable && $hours !== null)
                            @php($after = $hours['hours'] + $card['hours'])
                            @php($band = LoadBand::classify($after, $hours['base']))
                            <p class="flex items-start gap-2 text-sm" data-testid="selection-after">
                                <x-icon name="o-calculator" class="mt-0.5 h-4 w-4 flex-none text-neutral" aria-hidden="true" />
                                <span>Con este grupo llevarías <b class="font-mono">{{ $after }}</b> h · tu base <b class="font-mono">{{ $hours['base'] }}</b> (±2):
                                    <b @class(['text-success' => $band === 'within', 'text-warning' => $band !== 'within'])>{{ match ($band) {
                                        'under' => 'te faltan de '.($hours['base'] - 2 - $after).' a '.($hours['base'] + 2 - $after).' h',
                                        'over' => 'pasas tu margen por '.($after - $hours['base'] - 2).' h',
                                        default => 'dentro de tu margen',
                                    } }}</b></span>
                            </p>
                        @endif

                        @if ($pickable)
                            @include('staffing::offer.selection.rooms', ['card' => $card, 'options' => $options, 'chosen' => $chosen, 'mapPicker' => $mapPicker, 'roomTab' => $roomTab, 'space' => $space])
                        @endif
                        @error('group')
                            <p class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
                        @enderror
                    @else
                        @include('staffing::offer.selection.assign', ['card' => $card, 'candidates' => $candidates, 'managerRooms' => $managerRooms, 'canAssignRoom' => $canAssignRoom, 'teacherId' => $teacherId])
                    @endif
                </div>

                <footer class="sticky bottom-0 border-t border-base-300 bg-base-100 px-5 py-3">
                    @if ($unassigning)
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <x-button label="Volver" class="btn-ghost max-md:h-11" wire:click="$set('mode', 'assign')" />
                            <x-button :label="'Quitar a '.$card['teacher']" icon="o-user-minus" class="btn-error max-md:h-11" wire:click="unassign" spinner="unassign" data-testid="selection-unassign" />
                        </div>
                    @elseif ($assigning)
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <p class="me-auto text-sm text-neutral" data-testid="selection-assign-hint">
                                @if (! $changesTeacher && $changesRoom)
                                    Solo cambia el salón; el docente se queda. Queda en la bitácora con tu nombre.
                                @else
                                    Queda en la bitácora con tu nombre.
                                @endif
                            </p>
                            @if ($card['teacher_id'] !== null)
                                <x-button label="Quitar docente" icon="o-user-minus" class="btn-ghost text-error max-md:h-11" wire:click="confirmUnassign" spinner="confirmUnassign" data-testid="selection-unassign-open" />
                            @endif
                            @if ($entry !== null && $inTurn)
                                <x-button label="Ver como docente" class="btn-ghost max-md:h-11" wire:click="$set('mode', 'group')" />
                            @endif
                            <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
                            <x-button :label="match (true) { $changesTeacher && $chosenTeacher !== null => 'Asignar a '.$chosenTeacher['name'], $changesRoom => 'Guardar salón', default => 'Asignar' }"
                                :icon="! $changesTeacher && $changesRoom ? 'o-map-pin' : 'o-user-plus'" class="btn-primary max-md:h-11" wire:click="assign" spinner="assign"
                                :disabled="! $changesTeacher && ! $changesRoom" data-testid="selection-assign" />
                        </div>
                    @elseif ($pickable)
                        <p class="mb-2 text-sm text-neutral">Queda a tu nombre; lo puedes soltar mientras dure tu turno.</p>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($canAssign)
                                <x-button label="Asignar a…" icon="o-user-plus" class="btn-ghost me-auto max-md:h-11" wire:click="$set('mode', 'assign')" />
                            @endif
                            <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
                            <x-button :label="$options['eligible'] === [] ? 'Tomar sin salón' : ($chosen ? 'Tomar en '.$chosen['code'] : 'Elige un salón')" icon="o-plus" class="btn-primary max-md:h-11" wire:click="take" spinner="take" :disabled="$options['eligible'] !== [] && $chosen === null" data-testid="selection-take" />
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($releasable)
                                <x-button label="Soltar grupo" icon="o-minus-circle" class="btn-ghost me-auto text-error max-md:h-11" wire:click="release" spinner="release" data-testid="selection-release" />
                            @endif
                            @if ($canAssign)
                                <x-button label="Asignar a…" icon="o-user-plus" class="btn-ghost max-md:h-11" wire:click="$set('mode', 'assign')" />
                            @endif
                            <x-button label="Cerrar" class="btn-ghost max-md:h-11" @click="close()" />
                        </div>
                    @endif
                </footer>
            </div>
        @elseif ($gone)
            <div class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <h2 id="selection-group-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">Grupo</h2>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>
                <div class="flex-1 px-5 pt-4 pb-6">
                    <div role="status" class="alert alert-soft items-start text-sm">
                        <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                        <p>Este grupo ya no está en la selección: se canceló o se quitó su carga.</p>
                    </div>
                </div>
            </div>
        @endif
    </x-drawer>
</div>
