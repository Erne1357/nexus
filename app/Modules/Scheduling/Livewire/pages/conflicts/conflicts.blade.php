{{--
    Choques y avance (tarea 3.4; DESIGN.md, «Tablero de estado»: maqueta C con la vista A, decidida por el usuario el 7
    de noviembre de 2026). Vista principal: una pestaña por carrera, la cuadrícula de sus retículas por semestre (las
    viejas con «Prioridad») y el detalle de la celda elegida. Vista «Departamentos»: cinco cifras, la matriz por
    departamento y su drawer. Cada pendiente enlaza a la pantalla que lo corrige.
--}}
<div @if ($pollUntil > now()->getTimestamp()) wire:poll.3s="refreshBoard" @endif>
    <x-header title="Choques y avance" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-4!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            @if ($term && $campus)
                Lo que le falta a la carga de {{ $campus->name }} para cerrar en {{ $term->name }}: choques duros, grupos sin horario, sin docente y sin salón.
            @else
                Lo que le falta a cada carga para cerrar: choques duros, grupos sin horario, sin docente y sin salón.
            @endif
        </x-slot:subtitle>
    </x-header>

    @if ($summary === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="conflicts-no-context">
            <x-icon name="o-map-pin" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $term === null ? 'No hay un periodo activo.' : 'Elige un campus.' }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">El tablero muestra la carga de un campus en un periodo: elígelos en tu credencial, arriba a la derecha.</p>
        </div>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="join" role="group" aria-label="Vista">
                <button type="button" wire:click="$set('view', 'reticula')" @class(['btn join-item', 'btn-primary' => $view === 'reticula', 'btn-outline border-base-300 bg-base-100' => $view !== 'reticula']) aria-pressed="{{ $view === 'reticula' ? 'true' : 'false' }}" data-testid="view-grid">Por carrera y semestre</button>
                <button type="button" wire:click="$set('view', 'departamentos')" @class(['btn join-item', 'btn-primary' => $view === 'departamentos', 'btn-outline border-base-300 bg-base-100' => $view !== 'departamentos']) aria-pressed="{{ $view === 'departamentos' ? 'true' : 'false' }}" data-testid="view-departments">Por departamento</button>
            </div>
            <div class="flex w-full items-center gap-3 md:ms-auto md:w-auto">
                <span class="flex items-center gap-1.5 text-sm text-neutral" data-testid="synced-at">
                    <x-icon name="o-arrow-path" class="h-4 w-4" aria-hidden="true" />
                    {{ $syncedAt ? 'Recalculado '.($syncedAt->isToday() ? 'hoy a las '.$syncedAt->format('H:i') : 'el '.$syncedAt->translatedFormat('j M, H:i')) : 'Sin recalcular todavía' }}
                </span>
                <x-button icon="o-arrow-path" class="btn-outline ms-auto md:ms-0" wire:click="recompute" spinner="recompute" data-testid="recompute">Recalcular</x-button>
            </div>
        </div>

        @if ($summary->groups === 0)
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="conflicts-empty">
                <x-icon name="o-squares-2x2" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
                <p class="font-semibold">Todavía no hay grupos que puedas ver en {{ $campus->name }}.</p>
                <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">Los grupos nacen cuando un jefe acepta la parte de su departamento en una propuesta de carrera.</p>
            </div>
        @elseif ($view !== 'departamentos')
            <div class="relative mb-3 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-base-300 [scrollbar-width:none]" role="tablist" aria-label="Carreras" data-testid="career-tabs">
                @foreach ($careers as $tab)
                    @php($active = $tab['id'] === $careerId)
                    <button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" wire:key="tab-{{ $tab['id'] }}" wire:click="$set('career', '{{ $tab['id'] }}')"
                        @class(['-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 whitespace-nowrap', 'border-primary font-semibold text-base-content' => $active, 'border-transparent text-neutral hover:text-base-content' => ! $active])
                        title="{{ $tab['name'] }}" data-testid="career-tab-{{ $tab['code'] }}">
                        <span class="font-mono font-semibold">{{ $tab['code'] }}</span>
                        @if ($tab['count']->isReady())
                            <x-icon name="o-check-circle" class="h-4 w-4 text-success" aria-hidden="true" /><span class="sr-only">lista para cerrar</span>
                        @else
                            <span @class(['badge badge-sm badge-soft font-mono', 'badge-error' => $tab['count']->hard > 0, 'badge-warning' => $tab['count']->hard === 0])>{{ $tab['count']->missing }}<span class="sr-only"> grupos con pendientes</span></span>
                        @endif
                    </button>
                @endforeach
            </div>

            @php($careerTab = collect($careers)->firstWhere('id', $careerId))
            <p class="mb-3 flex flex-wrap items-baseline gap-x-4 gap-y-1" data-testid="career-summary">
                <span class="font-semibold">{{ $careerTab['name'] }}</span>
                <span class="text-neutral"><span class="font-mono font-semibold text-base-content">{{ $careerCount->ready() }}</span> de <span class="font-mono">{{ $careerCount->groups }}</span> grupos listos</span>
                @if ($careerCount->hard > 0)<span class="text-error"><span class="font-mono font-semibold">{{ $careerCount->hard }}</span> con choque duro</span>@endif
                @if ($careerCount->pending > 0)<span class="text-warning"><span class="font-mono font-semibold">{{ $careerCount->pending }}</span> con pendientes</span>@endif
            </p>

            <div class="relative overflow-x-auto rounded-box border border-base-300 bg-base-100" data-testid="career-grid">
                <table class="w-full min-w-[66rem] table-fixed border-separate border-spacing-0 text-sm">
                    <caption class="sr-only">Grupos de {{ $careerTab['name'] }} por retícula y semestre</caption>
                    <colgroup><col class="w-48 md:w-56">@for ($s = 1; $s <= $grid['semesters']; $s++)<col>@endfor</colgroup>
                    <thead>
                        <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                            <th scope="col" class="sticky left-0 z-10 border-e border-base-300 bg-base-200 px-4 py-2.5 text-start font-medium">Retícula</th>
                            @for ($s = 1; $s <= $grid['semesters']; $s++)
                                <th scope="col" class="bg-base-200 px-1.5 py-2.5 text-center font-medium whitespace-nowrap"><span class="max-desktop:hidden">Semestre </span><span class="desktop:hidden">Sem. </span>{{ $s }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid['plans'] as $plan)
                            <tr wire:key="plan-{{ $plan['id'] }}">
                                <th scope="row" class="sticky left-0 z-10 border-e border-t border-base-300 bg-base-100 px-4 py-2.5 text-start align-top font-normal">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold">{{ $plan['name'] }}
                                        @if ($plan['priority'])<span class="rounded-selector border border-dashed border-neutral/60 px-1.5 text-[11px] font-medium text-neutral" title="Retícula vieja: ya no entran alumnos">Prioridad</span>@endif
                                    </span>
                                    <span class="font-mono text-xs text-neutral">{{ $plan['code'] }}</span>
                                </th>
                                @for ($s = 1; $s <= $grid['semesters']; $s++)
                                    @php($count = $plan['cells'][$s] ?? null)
                                    @php($selected = $cellPlan !== null && $cellPlan['id'] === $plan['id'] && $cellSemester === $s)
                                    <td class="border-t border-base-300 p-1.5 align-top">
                                        @if ($count === null)
                                            <div class="flex h-16 flex-col justify-between rounded-field border border-base-300 bg-[repeating-linear-gradient(135deg,var(--color-base-200)_0_6px,var(--color-base-100)_6px_12px)] px-2 py-1.5 text-xs text-neutral/70" aria-label="Semestre {{ $s }}: sin grupos">
                                                <span class="font-mono">—</span><span class="whitespace-nowrap">Sin grupos</span>
                                            </div>
                                        @else
                                            @php($state = $count->isReady() ? 'ready' : ($count->hard > 0 ? 'hard' : 'pending'))
                                            <button type="button" wire:click="selectCell('{{ $plan['id'] }}', {{ $s }})" aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                                @class([
                                                    'flex h-16 w-full flex-col justify-between rounded-field border px-2 py-1.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                                    'border-success/40 bg-success/10 hover:bg-success/15' => $state === 'ready',
                                                    'border-error/40 bg-error/10 hover:bg-error/15' => $state === 'hard',
                                                    'border-warning/40 bg-warning/10 hover:bg-warning/15' => $state === 'pending',
                                                    'ring-2 ring-primary ring-offset-1 ring-offset-base-100' => $selected,
                                                ])
                                                aria-label="{{ $plan['name'] }}, semestre {{ $s }}: {{ $count->groups }} {{ $count->groups === 1 ? 'grupo' : 'grupos' }}{{ $state === 'ready' ? ', listo' : '' }}{{ $count->hard ? ", {$count->hard} con choque duro" : '' }}{{ $count->pending ? ", {$count->pending} con pendientes" : '' }}"
                                                data-testid="cell-{{ $plan['code'] }}-{{ $s }}">
                                                <span class="font-mono text-xs font-semibold whitespace-nowrap text-neutral">{{ $count->groups }} {{ $count->groups === 1 ? 'grupo' : 'grupos' }}</span>
                                                <span class="flex items-center gap-2 font-mono text-[13px] font-semibold">
                                                    @if ($state === 'ready')
                                                        <x-icon name="o-check-circle" class="h-4 w-4 text-success" aria-hidden="true" />
                                                    @endif
                                                    @if ($count->hard > 0)
                                                        <span class="flex items-center gap-0.5 text-error"><x-icon name="o-x-circle" class="h-4 w-4" aria-hidden="true" />{{ $count->hard }}</span>
                                                    @endif
                                                    @if ($count->pending > 0)
                                                        <span class="flex items-center gap-0.5 text-warning"><x-icon name="o-clock" class="h-4 w-4" aria-hidden="true" />{{ $count->pending }}</span>
                                                    @endif
                                                </span>
                                            </button>
                                        @endif
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-neutral" aria-label="Leyenda">
                <li class="flex items-center gap-2"><span class="inline-flex h-5 w-6 items-center justify-center rounded-field border border-success/40 bg-success/10"><x-icon name="o-check-circle" class="h-3.5 w-3.5 text-success" aria-hidden="true" /></span>Listo</li>
                <li class="flex items-center gap-2"><span class="inline-flex h-5 w-6 items-center justify-center rounded-field border border-error/40 bg-error/10"><x-icon name="o-x-circle" class="h-3.5 w-3.5 text-error" aria-hidden="true" /></span>Con choque duro</li>
                <li class="flex items-center gap-2"><span class="inline-flex h-5 w-6 items-center justify-center rounded-field border border-warning/40 bg-warning/10"><x-icon name="o-clock" class="h-3.5 w-3.5 text-warning" aria-hidden="true" /></span>Sin horario, sin docente o sin salón</li>
                <li class="flex items-center gap-2"><span class="inline-flex h-5 w-6 rounded-field border border-base-300 bg-[repeating-linear-gradient(135deg,var(--color-base-200)_0_4px,var(--color-base-100)_4px_8px)]"></span>Sin grupos</li>
                <li class="flex items-center gap-2"><span class="rounded-selector border border-dashed border-neutral/60 px-1.5 text-[11px] font-medium">Prioridad</span>Retícula vieja: ya no entran alumnos</li>
            </ul>

            @if ($cellDetail !== null)
                <section class="@container mt-4 rounded-box border border-base-300 bg-base-100 px-4 pt-3 pb-1 md:px-5" aria-labelledby="cell-title" data-testid="cell-detail">
                    <div class="flex flex-wrap items-start gap-3 border-b border-base-300 pb-3">
                        <div>
                            <p class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $careerTab['code'] }} · {{ $cellPlan['name'] }} · Semestre {{ $cellSemester }}</p>
                            <h2 id="cell-title" class="text-lg font-bold">{{ $cellCount->groups }} {{ $cellCount->groups === 1 ? 'grupo' : 'grupos' }} · {{ $cellCount->ready() }} {{ $cellCount->ready() === 1 ? 'listo' : 'listos' }}</h2>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto" wire:click="$set('cell', '')" aria-label="Cerrar el detalle"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                    </div>
                    @include('scheduling::conflicts.detail', ['detail' => $cellDetail, 'prefix' => 'cell', 'wide' => true])
                </section>
            @endif
        @else
            <section class="mb-4 grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:grid-cols-5" aria-label="Resumen" data-testid="summary">
                @foreach ([
                    ['Grupos con choque duro', $summary->hard, 'text-error', 'o-x-circle'],
                    ['Grupos sin horario', $summary->unscheduled, '', null],
                    ['Grupos sin docente', $summary->withoutTeacher, '', null],
                    ['Grupos sin salón', $summary->withoutRoom, '', null],
                ] as [$label, $value, $tone, $icon])
                    <div class="border-base-300 p-4 not-first:border-s max-lg:[&:nth-child(3)]:border-s-0 max-lg:[&:nth-child(n+3)]:border-t">
                        <p class="flex items-center gap-2 font-mono text-[26px] leading-tight font-semibold {{ $tone }}">@if ($icon)<x-icon :name="$icon" class="h-5 w-5" aria-hidden="true" />@endif{{ $value }}</p>
                        <p class="text-sm text-neutral">{{ $label }}</p>
                    </div>
                @endforeach
                <div class="col-span-2 border-t border-base-300 p-4 lg:col-span-1 lg:border-s lg:border-t-0">
                    <p class="font-mono text-[26px] leading-tight font-semibold">{{ $summary->ready() }} <span class="text-sm font-normal text-neutral">de {{ $summary->groups }}</span></p>
                    <p class="text-sm text-neutral">Grupos listos para cerrar</p>
                </div>
            </section>

            <div class="relative overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="department-matrix">
                <div class="relative hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[50rem] text-sm">
                        <caption class="sr-only">Lo que le falta a cada departamento</caption>
                        <thead class="bg-base-200">
                            <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                <th scope="col" class="px-4 py-2.5 text-start font-medium">Departamento</th>
                                <th scope="col" class="px-2 py-2.5 text-end font-medium">Grupos</th>
                                <th scope="col" class="px-2 py-2.5 text-center font-medium">Choque duro</th>
                                <th scope="col" class="px-2 py-2.5 text-center font-medium">Sin horario</th>
                                <th scope="col" class="px-2 py-2.5 text-center font-medium">Sin docente</th>
                                <th scope="col" class="px-2 py-2.5 text-center font-medium">Sin salón</th>
                                <th scope="col" class="px-4 py-2.5 text-start font-medium">Para cerrar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            @foreach ($departments as $row)
                                <tr wire:key="dept-{{ $row['id'] }}" wire:click="openDepartment('{{ $row['id'] }}')" class="cursor-pointer hover:bg-base-200/50" data-testid="department-row-{{ $row['code'] }}">
                                    <th scope="row" class="px-4 py-2.5 text-start font-normal">
                                        <button type="button" class="text-start" wire:click.stop="openDepartment('{{ $row['id'] }}')"><span class="me-2 font-mono font-semibold">{{ $row['code'] }}</span><span class="font-semibold">{{ $row['name'] }}</span></button>
                                    </th>
                                    <td class="px-2 py-2.5 text-end font-mono tabular-nums">{{ $row['count']->groups }}</td>
                                    <td class="px-2 py-2.5 text-center">@include('scheduling::conflicts.count', ['kind' => 'hard', 'n' => $row['count']->hard])</td>
                                    <td class="px-2 py-2.5 text-center">@include('scheduling::conflicts.count', ['kind' => 'unscheduled', 'n' => $row['count']->unscheduled])</td>
                                    <td class="px-2 py-2.5 text-center">@include('scheduling::conflicts.count', ['kind' => 'teacher', 'n' => $row['count']->withoutTeacher])</td>
                                    <td class="px-2 py-2.5 text-center">@include('scheduling::conflicts.count', ['kind' => 'room', 'n' => $row['count']->withoutRoom])</td>
                                    <td class="px-4 py-2.5">
                                        @if ($row['count']->isReady())
                                            <x-core::status-badge tone="success" label="Listo para cerrar" />
                                        @else
                                            <x-core::status-badge tone="neutral" icon="o-clock" :label="'Faltan '.$row['count']->missing" class="font-mono" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-base-300 bg-base-200/60">
                            <tr class="font-semibold">
                                <th scope="row" class="px-4 py-2.5 text-start">{{ $campus->name }} · {{ count($departments) }} {{ count($departments) === 1 ? 'departamento' : 'departamentos' }}</th>
                                <td class="px-2 py-2.5 text-end font-mono">{{ $summary->groups }}</td>
                                <td class="px-2 py-2.5 text-center font-mono">{{ $summary->hard }}</td>
                                <td class="px-2 py-2.5 text-center font-mono">{{ $summary->unscheduled }}</td>
                                <td class="px-2 py-2.5 text-center font-mono">{{ $summary->withoutTeacher }}</td>
                                <td class="px-2 py-2.5 text-center font-mono">{{ $summary->withoutRoom }}</td>
                                <td class="px-4 py-2.5">{{ $summary->ready() }} listos</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ul class="divide-y divide-base-300 md:hidden">
                    @foreach ($departments as $row)
                        <li wire:key="dept-m-{{ $row['id'] }}">
                            <button type="button" class="grid w-full gap-2 px-4 py-3 text-start" wire:click="openDepartment('{{ $row['id'] }}')">
                                <span class="flex items-start justify-between gap-3"><span><span class="me-1.5 font-mono font-semibold">{{ $row['code'] }}</span>{{ $row['name'] }}</span>
                                    @if ($row['count']->isReady())<x-core::status-badge tone="success" label="Listo" />@else<x-core::status-badge tone="neutral" icon="o-clock" :label="'Faltan '.$row['count']->missing" class="font-mono" />@endif
                                </span>
                                <span class="flex flex-wrap gap-1.5">
                                    <span class="badge badge-neutral badge-soft font-mono">{{ $row['count']->groups }} grupos</span>
                                    @foreach (['hard' => $row['count']->hard, 'unscheduled' => $row['count']->unscheduled, 'teacher' => $row['count']->withoutTeacher, 'room' => $row['count']->withoutRoom] as $kind => $n)
                                        @if ($n > 0)@include('scheduling::conflicts.count', ['kind' => $kind, 'n' => $n])@endif
                                    @endforeach
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="drawer-title" data-testid="department-drawer">
        @if ($drawer ?? null)
            <div class="flex h-full flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    <div>
                        <h2 id="drawer-title" class="text-xl font-bold">{{ $drawer['name'] }} <span class="font-mono text-base font-semibold text-neutral">{{ $drawer['code'] }}</span></h2>
                        <p class="text-sm text-neutral">{{ $drawer['count']->groups }} grupos · {{ $drawer['count']->missing === 0 ? 'listo para cerrar' : "faltan {$drawer['count']->missing}" }}</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto" wire:click="closeDrawer" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5">
                    @include('scheduling::conflicts.detail', ['detail' => $drawer['detail'], 'prefix' => 'drawer'])
                </div>
                <p class="border-t border-base-300 px-5 py-3 text-sm text-neutral">Cada pendiente se corrige en su pantalla; aquí solo se ve.</p>
            </div>
        @endif
    </x-drawer>
</div>
