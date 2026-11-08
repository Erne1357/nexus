{{--
    Bandeja «Grupos del semestre»: los grupos por materia, en el orden de la retícula (la materia con su clave en Mono
    y su nombre). Cada grupo es una pieza que lo elige (`aria-pressed`) con la franja de su materia, «CLAVE · grupo»,
    sus horas «N de M h» con su icono y el docente o «Sin docente»; el botón de su esquina abre el cajón del grupo.
    Desde 1200 px siempre abierta y con su propio desplazamiento; antes, plegable (abierta desde 768 px; en celular
    cerrada, y se cierra al elegir un grupo). En celular las piezas de cada materia se desplazan de lado.
--}}
@php
    $missing = count(array_filter($groups, fn (array $group): bool => $group['placed_hours'] < $group['required_hours']));
@endphp
<section class="order-1 flex min-h-0 min-w-0 flex-col rounded-box border border-base-300 bg-base-100 desktop:min-h-45 desktop:flex-[1_1_auto]"
    x-data="{ open: matchMedia('(min-width: 48rem)').matches, wide: matchMedia('(min-width: 75rem)').matches }"
    x-init="matchMedia('(min-width: 75rem)').addEventListener('change', (event) => wide = event.matches);
        const reveal = () => $nextTick(() => wide && $el.querySelector('[data-group][aria-pressed=true]')?.scrollIntoView({ block: 'nearest' }));
        reveal();
        $wire.$watch('groupId', reveal)"
    aria-labelledby="tray-title" data-testid="tray">
    <h2 id="tray-title">
        <button type="button" class="flex w-full flex-wrap items-center gap-2 rounded-box px-3.5 py-3 text-left font-bold focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-default"
            x-on:click="open = ! open" x-bind:aria-expanded="(open || wide) ? 'true' : 'false'" x-bind:disabled="wide" aria-controls="tray-body">
            Grupos del semestre
            <span class="badge badge-neutral badge-soft badge-sm font-mono">{{ count($groups) }}</span>
            @if ($missing > 0)
                <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="(string) $missing"><span class="sr-only">sin acomodar</span></x-core::status-badge>
            @endif
            <x-icon name="o-chevron-down" class="ms-auto h-5 w-5 transition-transform motion-reduce:transition-none desktop:hidden" x-bind:class="open || '-rotate-90'" aria-hidden="true" />
        </button>
    </h2>

    <div id="tray-body" x-show="open || wide" class="grid min-h-0 content-start gap-3 overflow-y-auto px-3 pb-3">
        @forelse ($subjects as $row)
            <div class="grid min-w-0 gap-1.5" role="group" aria-label="{{ $row['subject']['code'] }} {{ $row['subject']['name'] }}">
                <h3 class="flex min-w-0 items-baseline gap-2 px-0.5 text-[13px] font-bold">
                    <span class="shrink-0 font-mono whitespace-nowrap">{{ $row['subject']['code'] }}</span>
                    <span class="truncate" title="{{ $row['subject']['name'] }}">{{ $row['subject']['name'] }}</span>
                    <span class="ms-auto font-mono font-normal whitespace-nowrap text-neutral">{{ count($row['groups']) }} {{ count($row['groups']) === 1 ? 'grupo' : 'grupos' }}</span>
                </h3>
                <div class="grid gap-1.5 max-md:auto-cols-[16.5rem] max-md:grid-flow-col max-md:overflow-x-auto max-md:pb-1.5 md:grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] desktop:grid-cols-1">
                    @foreach ($row['groups'] as $group)
                        @php($done = $group['placed_hours'] >= $group['required_hours'])
                        <div class="relative min-w-0" wire:key="piece-{{ $group['id'] }}">
                            <button type="button" wire:click="pick('{{ $group['id'] }}')" aria-pressed="{{ $group['id'] === $groupId ? 'true' : 'false' }}" data-group="{{ $group['id'] }}"
                                x-on:click="if (! matchMedia('(min-width: 48rem)').matches) { open = false }"
                                @class([
                                    $chainClasses[$row['chain'] % count($chainClasses)],
                                    'grid w-full min-w-0 gap-px rounded-field border border-base-300 bg-[color-mix(in_oklab,var(--chain)_7%,var(--color-base-100))] py-1.5 ps-3.5 pe-2.5 text-left shadow-[inset_4px_0_0_var(--chain)] cursor-pointer',
                                    'hover:border-primary aria-pressed:border-primary aria-pressed:ring-3 aria-pressed:ring-primary/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                ])>
                                <span class="flex min-w-0 items-center justify-between gap-2">
                                    <b class="truncate font-mono text-[13px] font-semibold">{{ $group['subject']['code'] }} · {{ $group['code'] }}</b>
                                    <span @class(['inline-flex items-center gap-1 font-mono text-xs whitespace-nowrap', 'text-success' => $done, 'text-warning' => ! $done])>
                                        <x-icon :name="$done ? 'o-check-circle' : 'o-exclamation-triangle'" class="h-3.5 w-3.5" aria-hidden="true" />{{ $group['placed_hours'] }} de {{ $group['required_hours'] }} h
                                    </span>
                                </span>
                                <span @class(['flex min-w-0 items-center gap-1.5 pe-7 text-[12.5px]', 'text-neutral' => $group['teacher'], 'text-warning' => ! $group['teacher']])>
                                    <x-icon :name="$group['teacher'] ? 'o-user' : 'o-user-minus'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ $group['teacher'] ?? 'Sin docente' }}</span>
                                </span>
                                @if ($group['shared'])
                                    <span class="flex items-center gap-1.5 pe-7 text-[12.5px] text-neutral">
                                        <x-icon name="o-arrows-right-left" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Grupo de {{ collect($group['quotas'])->firstWhere('owner', true)['career_code'] ?? 'otra carrera' }}
                                    </span>
                                @endif
                            </button>
                            <x-button icon="o-information-circle" class="btn-ghost btn-xs btn-square absolute right-1 bottom-1" wire:click="openGroup('{{ $group['id'] }}')" data-opener
                                aria-label="Grupo {{ $group['subject']['code'] }} · {{ $group['code'] }}: sesiones y cupos" title="Sesiones y cupos" />
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="px-1 py-5 text-center text-neutral">Este semestre no tiene grupos en {{ $term->name }}.</p>
        @endforelse
    </div>
</section>
