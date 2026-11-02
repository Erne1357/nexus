{{--
    Avance de validación (DESIGN.md, "Patrones de pantalla previstos", «Tablero de estado»: variante A, matriz,
    decidida por el usuario el 2 de noviembre de 2026). Encabezado, su barra de herramientas (búsqueda, campus,
    estado y «Recordar a todos los pendientes»), tres indicadores (avance global, ventana de la etapa y
    observaciones abiertas) y la matriz: una tabla desde 1024 px y, debajo, renglones apilados con las mismas
    celdas. Tocar un departamento abre su drawer.
--}}
<div>
    <x-header title="Avance de validación" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-4!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            @if ($term)
                {{ $term->name }} · <span class="font-mono">{{ $term->code }}</span>
                @if ($open->isNotEmpty())
                    · {{ \Illuminate\Support\Arr::join($open->pluck('name')->all(), ', ', ' y ') }} {{ $open->count() > 1 ? 'abiertos' : 'abierto' }}
                @endif
            @else
                Qué departamentos ya validaron sus catálogos y cuáles faltan.
            @endif
        </x-slot:subtitle>
    </x-header>

    @if ($term === null || $board === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="progress-empty">
            <x-icon name="o-clipboard-document-check" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $term === null ? 'No hay un periodo activo.' : "{$term->name} no está abierto en ningún campus." }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">Las revisiones nacen al abrir el periodo en un campus (Periodos); mientras no haya una, no hay nada que seguir.</p>
        </div>
    @else
        @php($pendingHeads = $board['remindable']['heads'])
        <x-core::catalog-toolbar narrow>
            <x-slot:search>
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Departamento o jefe" aria-label="Buscar por departamento o jefe" clearable />
            </x-slot:search>

            <x-select wire:model.live="campus" aria-label="Campus" :options="$open->map(fn ($option) => ['id' => $option->id, 'name' => $option->name])->all()" placeholder="Campus" placeholder-value="" />
            <x-select wire:model.live="status" aria-label="Estado" :options="[['id' => 'pending', 'name' => 'Con pendientes'], ['id' => 'validated', 'name' => 'Todo validado']]" placeholder="Estado" placeholder-value="" />

            <x-slot:action>
                <x-button label="Recordar a todos los pendientes" icon="o-bell" class="btn-primary" wire:click="remind" spinner="remind" data-testid="remind-all" :disabled="$board['remindable']['departments'] === 0">
                    <span class="badge badge-sm badge-neutral" aria-label="{{ $pendingHeads }} jefes por recordar">{{ $pendingHeads }}</span>
                </x-button>
            </x-slot:action>
        </x-core::catalog-toolbar>

        <div class="mb-4 grid divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 bg-base-100 {{ $window ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }} lg:divide-x lg:divide-y-0" data-testid="progress-summary">
            <section class="space-y-2 p-4" aria-labelledby="progress-global" data-testid="progress-global">
                <h2 id="progress-global" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Avance global</h2>
                <p class="flex items-baseline gap-2"><span class="text-3xl font-semibold leading-none tabular-nums">{{ $board['global']['validated'] }} de {{ $board['global']['total'] }}</span><span class="text-neutral">revisiones validadas</span></p>
                @php($total = max(1, $board['global']['total']))
                <div class="flex h-2 overflow-hidden rounded-full bg-base-300" role="img" aria-label="{{ $board['global']['percent'] }} % validado">
                    <i class="bg-success" style="width: {{ $board['global']['validated'] / $total * 100 }}%"></i>
                    <i class="bg-info" style="width: {{ $board['global']['in_review'] / $total * 100 }}%"></i>
                </div>
                <p class="flex flex-wrap gap-x-4 text-sm text-neutral"><span><span class="font-mono tabular-nums">{{ $board['global']['percent'] }}</span> %</span><span>{{ $board['global']['in_review'] }} en revisión</span><span>{{ $board['global']['pending'] }} pendientes</span></p>
            </section>

            @if ($window)
                @php($stage = $window['window'])
                <section class="space-y-2 p-4" aria-labelledby="progress-stage" data-testid="progress-stage">
                    <h2 id="progress-stage" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Validación de catálogos</h2>
                    <p class="flex flex-wrap items-center gap-2"><span class="font-mono text-2xl font-semibold leading-none tabular-nums">{{ \App\Modules\Core\Support\StageTimeline::rangeLabel($stage) }}</span><x-core::status-badge :tone="$stage->status->tone()" :label="$stage->status->label()" /></p>
                    <div class="h-2 overflow-hidden rounded-full bg-base-300" aria-hidden="true"><i class="block h-full bg-primary" style="width: {{ $window['elapsed'] }}%"></i></div>
                    <p class="text-sm text-neutral">{{ \App\Modules\Core\Support\StageTimeline::daysLeftLabel($stage) ?? 'Etapa terminada' }}</p>
                </section>
            @endif

            <section class="space-y-2 p-4" aria-labelledby="progress-flags" data-testid="progress-flags">
                <h2 id="progress-flags" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Observaciones abiertas</h2>
                <p class="flex items-baseline gap-2"><span class="font-mono text-3xl font-semibold leading-none tabular-nums">{{ $board['flags']['open'] }}</span><span class="text-neutral">en {{ $board['flags']['departments'] }} {{ $board['flags']['departments'] === 1 ? 'departamento' : 'departamentos' }}</span></p>
                <p class="text-sm text-neutral">{{ $board['flags']['resolved'] }} {{ $board['flags']['resolved'] === 1 ? 'resuelta' : 'resueltas' }}</p>
            </section>
        </div>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="progress-matrix">
            @if ($board['rows'] === [])
                <div class="px-5 py-8 text-center">
                    <x-icon name="o-clipboard-document-check" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
                    <p class="font-semibold">Ningún departamento coincide con el filtro.</p>
                </div>
            @else
                <div class="hidden lg:block">
                    <table class="w-full table-fixed text-sm">
                        <caption class="sr-only">Estado de cada catálogo por departamento</caption>
                        <thead class="bg-base-200 text-start">
                            <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                <th scope="col" class="w-[20%] px-4 py-3 text-start font-medium">Departamento</th>
                                @foreach ($board['columns'] as $column)
                                    <th scope="col" class="px-2 py-3 text-start font-medium">{{ $column['catalog']->label() }}<span class="block text-[11px] font-normal normal-case tracking-normal">{{ $column['campus']?->name ?? 'Todo el Tec' }}</span></th>
                                @endforeach
                                <th scope="col" class="w-20 px-2 py-3 text-start font-medium">Validados</th>
                                <th scope="col" class="w-16 px-2 py-3 text-start font-medium">Obs.</th>
                                <th scope="col" class="w-36 px-2 py-3"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            @foreach ($board['rows'] as $row)
                                @php($department = $row['department'])
                                <tr wire:key="row-{{ $department->id }}" class="align-top" data-testid="progress-row-{{ $department->code }}">
                                    <td class="px-4 py-3">
                                        @include('catalog-validation::progress.who', ['row' => $row, 'campuses' => $campuses])
                                    </td>
                                    @foreach ($board['columns'] as $column)
                                        <td class="px-2 py-3" data-testid="cell-{{ $department->code }}-{{ $column['key'] }}">@include('catalog-validation::progress.cell', ['review' => $row['cells'][$column['key']]])</td>
                                    @endforeach
                                    <td class="px-2 py-3 whitespace-nowrap"><span class="font-semibold tabular-nums">{{ $row['validated'] }} de {{ $row['total'] }}</span></td>
                                    <td class="px-2 py-3">@include('catalog-validation::progress.flags', ['row' => $row])</td>
                                    <td class="px-2 py-2.5">
                                        <div class="flex items-center justify-end gap-1">
                                            @include('catalog-validation::progress.actions', ['row' => $row, 'inTable' => true])
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-base-300 bg-base-200/60" data-testid="progress-totals">
                            <tr>
                                <th scope="row" class="px-4 py-3 text-start font-semibold">Total del Tec<span class="block text-xs font-normal text-neutral">{{ count($board['rows']) }} {{ count($board['rows']) === 1 ? 'departamento' : 'departamentos' }}</span></th>
                                @foreach ($board['columns'] as $column)
                                    @php($totals = $board['totals'][$column['key']])
                                    <td class="px-2 py-3" data-testid="total-{{ $column['key'] }}">
                                        <span class="tabular-nums">{{ $totals['validated'] }} de {{ $totals['total'] }}</span>
                                        <span class="block text-xs text-neutral">{{ $totals['unvalidated'] }} sin validar</span>
                                        <i class="mt-1 block h-1 overflow-hidden rounded-full bg-base-300" aria-hidden="true"><b class="block h-full bg-success" style="width: {{ $totals['total'] === 0 ? 0 : $totals['validated'] / $totals['total'] * 100 }}%"></b></i>
                                    </td>
                                @endforeach
                                <td class="px-2 py-3 whitespace-nowrap"><span class="font-semibold tabular-nums">{{ array_sum(array_column($board['rows'], 'validated')) }} de {{ array_sum(array_column($board['rows'], 'total')) }}</span></td>
                                <td class="px-2 py-3 font-mono font-semibold tabular-nums">{{ array_sum(array_column($board['rows'], 'open_flags')) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <ul class="divide-y divide-base-300 lg:hidden" data-testid="progress-cards">
                    @foreach ($board['rows'] as $row)
                        @php($department = $row['department'])
                        <li wire:key="card-{{ $department->id }}" class="space-y-2 px-4 py-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">@include('catalog-validation::progress.who', ['row' => $row, 'campuses' => $campuses, 'stacked' => true])</div>
                                <p class="whitespace-nowrap text-sm"><span class="font-semibold tabular-nums">{{ $row['validated'] }} de {{ $row['total'] }}</span></p>
                            </div>
                            <dl class="grid grid-cols-[7.5rem_1fr] items-center gap-x-3 gap-y-1.5 text-sm">
                                @foreach ($board['columns'] as $column)
                                    <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $column['catalog']->label() }}@if ($column['campus']) <span class="block normal-case tracking-normal">{{ $column['campus']->code }}</span>@endif</dt>
                                    <dd>@include('catalog-validation::progress.cell', ['review' => $row['cells'][$column['key']], 'inline' => true])</dd>
                                @endforeach
                                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Observaciones</dt>
                                <dd>@include('catalog-validation::progress.flags', ['row' => $row])</dd>
                            </dl>
                            <div class="flex items-center justify-between gap-2">
                                @include('catalog-validation::progress.actions', ['row' => $row, 'inTable' => false])
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                    <p>{{ count($board['rows']) }} {{ count($board['rows']) === 1 ? 'departamento' : 'departamentos' }}</p>
                    <p>Cada departamento valida <span class="font-mono tabular-nums">{{ count($board['columns']) }}</span> catálogos</p>
                </div>
            @endif
        </div>
    @endif

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="progress-drawer-title" data-testid="progress-drawer">
        @if ($detail !== null)
            @include('catalog-validation::progress.drawer', ['row' => $detail['row'], 'flags' => $detail['flags'], 'columns' => $board['columns'] ?? [], 'term' => $term])
        @endif
    </x-drawer>
</div>
