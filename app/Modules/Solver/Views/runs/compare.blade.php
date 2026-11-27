{{--
    La corrida lista contra la carga (variante C): desde 1200 px, a la izquierda y pegada arriba, la bandeja de los grupos
    del lente con lo que cambia en cada uno y «Lo que queda» (los grupos que se quedan como están y por qué); antes, la
    bandeja se desplaza de lado y «Lo que queda» va debajo. A la derecha, «Con la corrida / Como está hoy / Las dos» con su
    leyenda, la barra del grupo elegido y la semana (la cuadrícula del editor de horarios, Core\Support\WeekGrid); bajo
    768 px, la lista de un día.
--}}
@php
    $changedInTray = count(array_filter($tray, fn (array $row): bool => $row['changed']));
@endphp
<div class="grid items-start gap-4 desktop:grid-cols-[18.25rem_minmax(0,1fr)]" data-testid="run-compare">
    <div class="grid min-w-0 gap-3 desktop:sticky desktop:top-[calc(var(--shell-top)+1rem)] desktop:flex desktop:max-h-[calc(100dvh-var(--shell-top)-2rem)] desktop:flex-col">
        <section class="flex min-h-0 min-w-0 flex-col rounded-box border border-base-300 bg-base-100 desktop:min-h-56 desktop:flex-[1_1_auto]" aria-labelledby="tray-title" data-testid="run-tray">
            <header class="flex items-center justify-between gap-2 border-b border-base-300 px-3.5 py-3">
                <h2 id="tray-title" class="font-bold">Grupos <span class="font-mono font-normal text-neutral">{{ count($tray) }}</span></h2>
                @if ($changedInTray > 0)
                    <x-core::status-badge tone="warning" icon="o-arrows-right-left" :label="$changedInTray === 1 ? '1 cambia' : $changedInTray.' cambian'" />
                @endif
            </header>
            <ul class="relative grid min-h-0 content-start gap-1.5 overflow-auto p-2 [scrollbar-width:thin] max-desktop:auto-cols-[15rem] max-desktop:grid-flow-col max-desktop:overflow-y-hidden max-desktop:overscroll-x-contain max-md:auto-cols-[13.5rem]">
                @forelse ($tray as $entry)
                    @php($group = $entry['group'])
                    <li class="min-w-0" wire:key="tray-{{ $group['id'] }}">
                        <button type="button" wire:click="selectGroup('{{ $group['id'] }}')" aria-pressed="{{ $selected === $group['id'] ? 'true' : 'false' }}" data-group="{{ $group['id'] }}"
                            @class([
                                $chainClasses[($chains[$group['subject_code']] ?? 0) % count($chainClasses)],
                                'grid w-full min-w-0 gap-0.5 rounded-field border border-base-300 bg-[color-mix(in_oklab,var(--chain)_7%,var(--color-base-100))] py-2 ps-3.5 pe-2.5 text-left shadow-[inset_4px_0_0_var(--chain)] cursor-pointer',
                                'hover:border-primary aria-pressed:border-primary aria-pressed:ring-3 aria-pressed:ring-primary/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            ])>
                            <span class="flex min-w-0 items-center gap-1.5">
                                <b class="truncate font-mono text-[13px] font-semibold">{{ $group['title'] }}</b>
                                @if ($view === 'docente' || $campusOptions->count() > 1)
                                    <x-core::campus-tag :campus="$campuses[$group['campus']] ?? (object) ['code' => $group['campus'], 'name' => $group['campus']]" />
                                @endif
                            </span>
                            <span class="truncate leading-snug font-semibold">{{ $group['subject_name'] }}</span>
                            <span class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[12.5px]">
                                @if ($entry['kept'])
                                    <span class="inline-flex items-center gap-1 text-error"><x-icon name="o-x-circle" class="h-3.5 w-3.5" aria-hidden="true" />Se queda como está</span>
                                @elseif ($entry['changed'])
                                    @if ($entry['new'] > 0)
                                        <span class="inline-flex items-center gap-1 text-info"><x-icon name="o-plus-circle" class="h-3.5 w-3.5" aria-hidden="true" /><span><span class="font-mono">{{ $entry['new'] }}</span> {{ $entry['new'] === 1 ? 'nueva' : 'nuevas' }}</span></span>
                                    @endif
                                    @if ($entry['moved'] > 0)
                                        <span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-arrows-right-left" class="h-3.5 w-3.5" aria-hidden="true" /><span><span class="font-mono">{{ $entry['moved'] }}</span> {{ $entry['moved'] === 1 ? 'movida' : 'movidas' }}</span></span>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 text-neutral"><x-icon name="o-minus-circle" class="h-3.5 w-3.5" aria-hidden="true" />Sin cambio</span>
                                @endif
                            </span>
                            <span class="flex min-w-0 items-center gap-1 text-[12.5px] text-neutral">
                                <x-icon :name="$group['teacher'] ? 'o-user' : 'o-user-minus'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ $group['teacher'] ?? 'Sin docente' }}</span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-1 py-5 text-center text-neutral">Sin grupos en esta vista.</li>
                @endforelse
            </ul>
        </section>

        <section class="flex min-h-0 min-w-0 flex-col rounded-box border border-base-300 bg-base-100 desktop:max-h-[calc((100dvh-var(--shell-top)-2rem)*0.4)] desktop:flex-none" aria-labelledby="left-title" data-testid="run-leftovers">
            <header class="flex items-center justify-between gap-2 border-b border-base-300 px-3.5 py-2.5">
                <h2 id="left-title" class="font-bold">Lo que queda</h2>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="openScore">Todo<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></button>
            </header>
            <div class="grid content-start gap-2 overflow-auto p-2.5 [scrollbar-width:thin] max-desktop:max-h-64">
                @forelse ($kept as $entry)
                    <div class="grid gap-0.5 rounded-field border border-error/40 bg-error/10 px-2.5 py-2 text-sm" wire:key="kept-{{ $entry['group']['id'] }}">
                        <span class="flex min-w-0 items-center gap-1.5 font-semibold text-error"><x-icon name="o-x-circle" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="truncate font-mono">{{ $entry['group']['title'] }}</span><span class="font-mono text-xs font-normal text-base-content">{{ $entry['group']['campus'] }}</span></span>
                        <p class="text-base-content">{{ implode(' ', $entry['reasons']) }}</p>
                    </div>
                @empty
                    <p class="flex items-center gap-2 rounded-field border border-success/30 bg-success/10 px-2.5 py-2 text-sm text-success"><x-icon name="o-check-circle" class="h-4 w-4 shrink-0" aria-hidden="true" /><span>Todos los grupos se escriben.</span></p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="grid min-w-0 content-start gap-2.5">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 max-md:flex-col max-md:items-stretch">
            <div class="join max-md:grid max-md:grid-cols-3" role="group" aria-label="Semana" data-testid="mode-filter">
                @foreach (\App\Modules\Solver\Queries\RunComparison::MODES as $key => $label)
                    <button type="button" wire:click="$set('mode', '{{ $key }}')" aria-pressed="{{ $mode === $key ? 'true' : 'false' }}"
                        @class(['btn join-item px-3 max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:px-1.5 max-md:leading-tight max-md:whitespace-normal', 'btn-primary' => $mode === $key])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12.5px] text-neutral" aria-hidden="true">
                @if ($mode === 'antes')
                    <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border-[1.5px] border-warning bg-warning/10"></i>Se mueve</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border border-base-300 bg-base-100 shadow-[inset_3px_0_0_color-mix(in_oklab,var(--color-neutral)_45%,var(--color-base-100))]"></i>Se queda</span>
                @else
                    <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border-[1.5px] border-info bg-info/10"></i>Nueva</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border-[1.5px] border-warning bg-warning/10"></i>Movida u otro salón<x-icon name="o-arrow-uturn-left" class="h-3 w-3" />de dónde viene</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border border-base-300 bg-base-100 shadow-[inset_3px_0_0_color-mix(in_oklab,var(--color-neutral)_45%,var(--color-base-100))]"></i>Sin cambio</span>
                    @if ($mode === 'ambas')
                        <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border-[1.5px] border-dashed border-warning bg-[image:repeating-linear-gradient(135deg,transparent_0_4px,color-mix(in_oklab,var(--color-warning)_14%,transparent)_4px_7px)]"></i>Lugar anterior</span>
                    @endif
                @endif
                <span class="inline-flex items-center gap-1.5"><i class="h-4 w-5.5 rounded-[4px] border border-dashed border-base-content/30 bg-base-200"></i>Fija (otra carga)</span>
            </div>
        </div>

        @if ($selectedGroup)
            <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-box border border-base-300 bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))] py-2 ps-3.5 pe-2" data-testid="selected-bar">
                <div class="min-w-48 grow">
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <b class="font-mono font-semibold">{{ $selectedGroup['title'] }}</b>
                        <x-core::campus-tag :campus="$campuses[$selectedGroup['campus']] ?? (object) ['code' => $selectedGroup['campus'], 'name' => $selectedGroup['campus']]" />
                        <span>{{ $selectedGroup['subject_name'] }}</span>
                        <span class="text-neutral">· {{ $selectedGroup['teacher'] ?? 'Sin docente' }}</span>
                    </p>
                    @if ($selectedLines !== [])
                        <ul class="mt-1.5 grid gap-0.5 font-mono text-[12.5px]">
                            @foreach ($selectedLines as $line)
                                <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span @class(['font-sans text-[11px] font-semibold', 'text-info' => $line['state'] === 'new', 'text-warning' => $line['state'] !== 'new'])>{{ $line['label'] }}</span>
                                    @if ($line['was'])
                                        <span @class(['text-neutral', 'line-through decoration-neutral/60' => $line['now'] !== null])>{{ $line['was'] }}</span>
                                    @endif
                                    @if ($line['was'] && $line['now'])
                                        <x-icon name="o-arrow-right" class="h-3.5 w-3.5 text-neutral" aria-hidden="true" /><span class="sr-only">a</span>
                                    @endif
                                    @if ($line['now'])
                                        <span>{{ $line['now'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($selectedGroup['status'] === \App\Modules\Solver\Rules\ApplyPlan::KEPT)
                        <p class="mt-1 text-sm text-error">{{ implode(' ', $selectedGroup['reasons']) }}</p>
                    @else
                        <p class="mt-1 text-sm text-neutral">Su horario no cambia.</p>
                    @endif
                </div>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="selectGroup('{{ $selectedGroup['id'] }}')"><x-icon name="o-x-mark" class="h-4 w-4" aria-hidden="true" />Quitar selección</button>
            </div>
        @endif

        @include('solver::runs.week')
        @include('solver::runs.day-list')
    </div>
</div>
