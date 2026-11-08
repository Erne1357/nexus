{{--
    Carga por docente (maqueta B «Mosaicos por estado», decidida por el usuario): encabezado con el departamento, «Todos
    los campus», el periodo y el estado de la carga; la franja de totales; la búsqueda y el filtro de plaza; y los
    mosaicos por sección. Tocar un mosaico abre el drawer del docente (hoja completa en celular). Lo que se pinta viene
    de TeacherLoadsBoardQuery.
--}}
@php
    $toneText = ['error' => 'text-error', 'warning' => 'text-warning', 'info' => 'text-info', 'success' => 'text-success', 'draft' => 'text-neutral'];
    $campusBar = ['bg-info', 'bg-primary', 'bg-neutral', 'bg-warning'];
@endphp
<div class="space-y-5">
    @if ($board === null)
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Carga por docente</h1>
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="loads-empty">{{ $message }}</div>
    @else
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold leading-tight md:text-3xl">Carga por docente</h1>
                <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                    <span class="font-medium text-base-content" data-testid="loads-department">{{ $department->name }}</span>
                    <span>Todos los campus</span>
                    <span data-testid="loads-term">{{ $term->name }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                @if (count($departments) > 1)
                    <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm" data-testid="loads-department-select" />
                @endif
                <div class="flex flex-col gap-1.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5" data-testid="loads-state">
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carga del departamento</span>
                    <span class="flex flex-wrap items-center gap-2">
                        @forelse ($loads as $load)
                            <x-core::status-badge :tone="$load->status->tone()" :label="$load->status->label()">
                                <span class="font-mono text-xs opacity-70">{{ $load->campus }}</span>
                            </x-core::status-badge>
                        @empty
                            <x-core::status-badge tone="draft" label="Sin carga" />
                        @endforelse
                    </span>
                </div>
            </div>
        </div>

        @if (! $board->isComputed())
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-10 text-center" data-testid="loads-not-computed">
                <x-icon name="o-user-group" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
                <p class="font-semibold">Todavía no se calcula la carga de este periodo.</p>
                <x-button label="Calcular ahora" icon="o-calculator" class="btn-primary mt-4" wire:click="calculate" spinner="calculate" data-testid="loads-calculate" />
            </div>
        @else
            <dl class="grid grid-cols-2 divide-x divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 bg-base-100 md:grid-cols-3 xl:grid-cols-6 xl:divide-y-0" data-testid="loads-totals">
                @php
                    $kpis = [
                        ['Docentes', $totals['teachers'], $totals['honorarios'].' honorarios'.($board->pending > 0 ? ' · '.$board->pending.' sin calcular' : ''), ''],
                        ['Horas base', $totals['base'], $totals['external'].' externas', ''],
                        ['Asignadas', $totals['assigned'], collect($totals['byCampus'])->map(fn ($hours, $code) => "{$code} {$hours}")->implode(' · ').' · '.$totals['groups'].' grupos', ''],
                        ['Restantes', $totals['remaining'], 'sin contar honorarios', ''],
                        ['Sin carga', $totals['none'], '', $totals['none'] > 0 ? 'text-warning' : ''],
                        ['Exceden', $totals['over'], 'HC-08', $totals['over'] > 0 ? 'text-error' : ''],
                    ];
                @endphp
                @foreach ($kpis as [$label, $value, $note, $tone])
                    <div class="min-w-0 space-y-1 p-4">
                        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                        <dd class="text-3xl font-semibold leading-none tabular-nums {{ $tone }}" data-testid="kpi-{{ Str::slug($label) }}">{{ $value }}</dd>
                        <dd class="truncate text-sm text-neutral">{{ $note }}&nbsp;</dd>
                    </div>
                @endforeach
            </dl>

            <x-core::catalog-toolbar narrow>
                <x-slot:search>
                    <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Nombre o RFC" aria-label="Buscar docente por nombre o RFC" clearable data-testid="loads-search" />
                </x-slot:search>

                <x-select wire:model.live="plaza" aria-label="Plaza" :options="$plazaOptions" placeholder="Plaza" placeholder-value="" />
            </x-core::catalog-toolbar>

            @forelse ($sections as $status => $section)
                <section class="space-y-3" aria-labelledby="section-{{ $status }}" data-testid="section-{{ $status }}">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h2 id="section-{{ $status }}" class="flex items-center gap-2 text-lg font-semibold {{ $toneText[$section['tone']] }}">
                            <x-icon :name="$section['icon']" class="h-5 w-5" aria-hidden="true" />
                            <span class="text-base-content">{{ $section['title'] }}</span>
                        </h2>
                        <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ count($section['rows']) }}</span>
                        <span class="text-sm text-neutral">{{ $section['totals']['assigned'] }} h asignadas</span>
                    </header>

                    <div class="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 min-[1000px]:grid-cols-3 min-[1500px]:grid-cols-4 min-[2000px]:grid-cols-5">
                        @foreach ($section['rows'] as $row)
                            @php
                                $scale = max(48, $row->assignedHours + $row->externalHours, $row->limitHours);
                                $pct = fn (int $hours): string => round($hours / $scale * 100, 2).'%';
                                $figure = match ($status) {
                                    'over' => ['−'.$row->excessHours().' h', 'de más', 'text-error'],
                                    'none' => ['0 / '.$row->baseHours, 'h', ''],
                                    'part' => [$row->remainingHours, 'libres', ''],
                                    'full' => ['0', 'libres', 'text-success'],
                                    default => [$row->assignedHours, 'h asignadas', ''],
                                };
                            @endphp
                            <button type="button" wire:click="open('{{ $row->teacherId }}')" wire:key="tile-{{ $row->teacherId }}" data-testid="tile" data-teacher="{{ $row->teacherId }}"
                                @class(['flex min-h-11 min-w-0 flex-col gap-2.5 rounded-box border bg-base-100 p-3.5 text-left transition-colors hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary', 'border-error' => $status === 'over', 'border-base-300' => $status !== 'over'])>
                                <span class="flex items-start justify-between gap-2">
                                    <span class="min-w-0 truncate font-semibold" title="{{ $row->name }}">{{ $row->name }}</span>
                                    <span class="flex shrink-0 flex-wrap justify-end gap-1">
                                        @foreach ($row->positionLabels as $label)
                                            <span class="badge badge-sm badge-neutral badge-soft font-mono" @if ($loop->first) data-testid="tile-plaza" @endif>{{ $label }}</span>
                                        @endforeach
                                    </span>
                                </span>
                                <span class="flex items-baseline gap-1.5">
                                    <b class="font-mono text-2xl font-semibold leading-none tabular-nums {{ $figure[2] }}" data-testid="tile-figure">{{ $figure[0] }}</b>
                                    <span class="text-sm text-neutral">{{ $figure[1] }}</span>
                                </span>
                                <span class="relative block h-2 rounded-full bg-base-300" role="img"
                                    aria-label="{{ collect($row->hoursByCampus)->map(fn ($hours, $code) => "{$hours} horas en {$code}")->implode(', ') }}{{ $row->externalHours ? ", {$row->externalHours} externas" : '' }}, base {{ $row->baseHours }}">
                                    <span class="absolute inset-y-0 left-0 flex w-full overflow-hidden rounded-full">
                                        @foreach ($row->hoursByCampus as $code => $hours)
                                            <i class="{{ $campusBar[$campusTones[$code] % count($campusBar)] }}" style="width: {{ $pct($hours) }}"></i>
                                        @endforeach
                                        @if ($row->externalHours)
                                            <i class="bg-base-content/35" style="width: {{ $pct($row->externalHours) }}"></i>
                                        @endif
                                    </span>
                                    @if ($row->baseHours)
                                        <i class="absolute -inset-y-0.5 w-0.5 bg-base-content" style="left: {{ $pct($row->baseHours) }}"></i>
                                    @endif
                                </span>
                                <span class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-neutral">
                                    <span><b class="font-mono text-base-content">{{ $row->assignedHours }}</b> asig.</span>
                                    @if ($row->externalHours)
                                        <span>ext. <b class="font-mono text-base-content">{{ $row->externalHours }}</b></span>
                                    @endif
                                    <span>base <b class="font-mono text-base-content">{{ $row->baseHours }}</b></span>
                                    <span><b class="font-mono text-base-content">{{ $row->groupsCount }}</b> {{ $row->groupsCount === 1 ? 'grupo' : 'grupos' }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="loads-no-match">
                    <p class="font-semibold">Ningún docente coincide</p>
                </div>
            @endforelse

            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-neutral" data-testid="loads-legend">
                @foreach (array_keys($totals['byCampus']) as $code)
                    <span class="flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm {{ $campusBar[$campusTones[$code] % count($campusBar)] }}"></i>{{ $code }}</span>
                @endforeach
                <span class="flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm bg-base-content/35"></i>Externas</span>
                <span class="flex items-center gap-1.5"><i class="inline-block h-3 w-0.5 bg-base-content"></i>Horas base</span>
            </div>
        @endif

        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-lg p-0!" role="dialog" aria-modal="true" aria-labelledby="teacher-drawer-title" data-testid="teacher-drawer">
            @if ($showDrawer && $selected !== null)
                @include('staffing::teacher-loads.drawer', ['row' => $selected, 'campusTones' => $campusTones, 'campusBar' => $campusBar])
            @endif
        </x-drawer>
    @endif
</div>
