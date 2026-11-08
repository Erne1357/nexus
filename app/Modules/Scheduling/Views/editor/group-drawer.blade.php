{{--
    El cajón del grupo (maqueta `cajones.html`, `#grupo`): su materia, «CLAVE · grupo», el departamento que imparte,
    la capacidad, el docente de solo lectura («Lo asigna Asignación docente»), sus sesiones (cada una abre su cajón) y
    «Cupos por carrera» (scheduling::components.group-quotas, que avisa con `quotas-saved` y la página se vuelve a
    pintar).
--}}
<x-drawer wire:model.live="showGroup" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="group-drawer-title" data-testid="group-drawer">
    @if ($drawerGroup)
        @php
            $short = array_column($grid->days ?? [], 'short', 'day');
            $placed = $drawerGroup['placed_hours'];
            $sessions = count($drawerGroup['sessions']);
        @endphp
        <div class="flex min-h-dvh flex-col">
            <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                <div class="min-w-0 flex-1">
                    <h2 id="group-drawer-title" class="text-xl leading-tight font-bold">{{ $drawerGroup['subject']['name'] }}</h2>
                    <p class="mt-0.5 font-mono text-[15px] font-semibold">{{ $drawerGroup['subject']['code'] }} · {{ $drawerGroup['code'] }}</p>
                </div>
                <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar" />
            </header>

            <div class="flex-1 space-y-5 px-5 pt-4 pb-8">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                    <div>
                        <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Imparte</dt>
                        <dd><span class="font-mono">{{ $drawerGroup['subject']['department_code'] }}</span> · {{ $drawerGroup['subject']['department_name'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Capacidad</dt>
                        <dd class="font-mono">{{ $drawerGroup['capacity'] }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Docente</dt>
                        <dd class="mt-1 flex items-start gap-2 rounded-field border border-base-300 bg-base-200 px-3 py-2">
                            <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span class="grid">
                                <b class="font-medium">{{ $drawerGroup['teacher'] ?? 'Sin docente' }}</b>
                                <small class="text-neutral">Lo asigna Asignación docente</small>
                            </span>
                        </dd>
                    </div>
                </dl>

                <section class="grid gap-2" aria-labelledby="group-sessions-title">
                    <h3 id="group-sessions-title" class="flex flex-wrap items-baseline justify-between gap-2 text-base font-semibold">
                        Sesiones
                        <span class="font-mono text-sm font-normal text-neutral">{{ $sessions }} {{ $sessions === 1 ? 'sesión' : 'sesiones' }} · {{ $placed }} de {{ $drawerGroup['required_hours'] }} h</span>
                    </h3>
                    @if ($sessions > 0)
                        <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                            @foreach ($drawerGroup['sessions'] as $row)
                                <li>
                                    <button type="button" wire:click="openSession('{{ $row['id'] }}')" class="grid w-full grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-2 px-3 py-2.5 text-left hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                                        <span>{{ $short[$row['day']] ?? $row['day'] }}</span>
                                        <span class="font-mono font-semibold">{{ \App\Modules\Scheduling\Support\WeekGrid::range($row['starts_at'], $row['ends_at']) }}</span>
                                        <span class="flex items-center gap-3 text-neutral">{{ $row['kind']->label() }} <span class="font-mono text-base-content">{{ $row['room']['code'] ?? 'Sin salón' }}</span></span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-neutral">Todavía no tiene sesiones.</p>
                    @endif
                </section>

                <livewire:scheduling::components.group-quotas :group-id="$drawerGroup['id']" :key="'quotas-'.$drawerGroup['id']" />
            </div>
        </div>
    @endif
</x-drawer>
