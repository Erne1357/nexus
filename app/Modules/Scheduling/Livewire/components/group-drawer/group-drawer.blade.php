{{--
    El cajón del grupo (maqueta `cajones.html`, `#grupo`; hasta 448 px y nunca más ancho que la ventana): su materia,
    «CLAVE · grupo», el departamento que imparte, la capacidad, el docente de solo lectura («Lo asigna Asignación
    docente»), sus sesiones (cada una abre su cajón, sin regresar el foco: `data-drawer-switch`) y «Cupos por carrera»
    (scheduling::components.group-quotas), que llena el resto del cajón y lleva «Guardar cupos» en el pie fijo, como el
    cajón de la sesión. «Campus» (M8, MS9) solo aparece si quien lo abre puede cambiarlo a otro campus: un select y
    «Cambiar de campus» con confirmación (ADR-0023).
--}}
<div>
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="group-drawer-title" data-testid="group-drawer">
        @if ($panel)
            @php($sessions = count($panel['sessions']))
            <div class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="group-drawer-title" class="text-xl leading-tight font-bold">{{ $panel['subject'] }}</h2>
                        <p class="mt-0.5 font-mono text-[15px] font-semibold">{{ $panel['code'] }}</p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar" />
                </header>

                <div class="flex flex-1 flex-col gap-5 px-5 pt-4">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Imparte</dt>
                            <dd>{{ $panel['department'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Capacidad</dt>
                            <dd class="font-mono">{{ $panel['capacity'] }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Docente</dt>
                            <dd class="mt-1 flex items-start gap-2 rounded-field border border-base-300 bg-base-200 px-3 py-2">
                                <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="grid">
                                    <b class="font-medium">{{ $panel['teacher'] ?? 'Sin docente' }}</b>
                                    <small class="text-neutral">Lo asigna Asignación docente</small>
                                </span>
                            </dd>
                        </div>
                    </dl>

                    @if ($campusOptions !== [])
                        <section class="grid gap-2" aria-labelledby="group-campus-title" data-testid="group-campus">
                            <h3 id="group-campus-title" class="text-base font-semibold">Campus</h3>
                            <p class="text-sm text-neutral">Es de {{ $currentCampus->name }}. Para cambiarlo, la carga debe estar abierta y sus sesiones sin salón.</p>
                            <div class="flex flex-wrap items-end gap-2">
                                <x-select wire:model="targetCampus" :options="$campusOptions" placeholder="Elige el campus" placeholder-value="" aria-label="Campus nuevo" class="min-w-40 flex-1" data-testid="group-campus-select" :disabled="$moveBlocked" />
                                @if ($moveBlocked)
                                    <button type="button" class="btn btn-outline max-sm:h-11" disabled aria-describedby="group-campus-blocked" data-testid="group-campus-move-disabled">Cambiar de campus</button>
                                @else
                                    <x-button class="btn-outline max-sm:h-11" wire:click="moveToCampus" spinner="moveToCampus"
                                        x-confirm="El grupo deja {{ $currentCampus->name }} con sus sesiones; el salón se asigna en el campus nuevo."
                                        data-confirm-title="¿Cambiar {{ $panel['code'] }} de campus?" data-confirm-button="Cambiar" data-confirm-tone="warning"
                                        data-testid="group-campus-move">Cambiar de campus</x-button>
                                @endif
                            </div>
                            @if ($moveReasons !== [])
                                <p id="group-campus-blocked" class="flex items-start gap-1.5 text-sm text-neutral" data-testid="group-campus-blocked"><x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ implode(' ', $moveReasons) }}</p>
                            @endif
                            @error('campus')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
                        </section>
                    @endif

                    <section class="grid gap-2" aria-labelledby="group-sessions-title">
                        <h3 id="group-sessions-title" class="flex flex-wrap items-baseline justify-between gap-2 text-base font-semibold">
                            Sesiones
                            <span class="text-sm font-normal text-neutral"><span class="font-mono">{{ $sessions }}</span> {{ $sessions === 1 ? 'sesión' : 'sesiones' }} · <span class="font-mono">{{ $panel['placed'] }}</span> de <span class="font-mono">{{ $panel['required'] }}</span> h</span>
                        </h3>
                        @if ($sessions > 0)
                            <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                                @foreach ($panel['sessions'] as $row)
                                    <li>
                                        <button type="button" data-drawer-switch x-on:click="close()"
                                            wire:click="$dispatchTo('scheduling::components.session-drawer', 'open-session', { id: '{{ $row['id'] }}' })"
                                            class="grid w-full grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-2 px-3 py-2.5 text-left hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                                            <span>{{ $row['day'] }}</span>
                                            <span class="font-mono font-semibold">{{ $row['hours'] }}</span>
                                            <span class="flex items-center gap-3 text-neutral">{{ $row['kind'] }} @if ($row['room'])<span class="font-mono text-base-content">{{ $row['room'] }}</span>@else<span class="text-base-content">Sin salón</span>@endif</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-neutral">Todavía no tiene sesiones.</p>
                        @endif
                    </section>

                    <livewire:scheduling::components.group-quotas :group-id="$panel['id']" :key="'quotas-'.$panel['id']" />
                </div>
            </div>
        @endif
    </x-drawer>
</div>
