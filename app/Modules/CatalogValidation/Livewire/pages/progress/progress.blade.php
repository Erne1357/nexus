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
        @if ($canOpen && $term && $open->isNotEmpty() && collect($options)->contains(fn ($option) => ! $option['open']))
            <x-slot:actions>
                <x-button icon="o-lock-open" class="btn-primary" wire:click="startOpening" data-testid="open-validation">Abrir validación</x-button>
            </x-slot:actions>
        @endif
    </x-header>

    @if ($term === null || $board === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="progress-empty">
            <x-icon name="o-clipboard-document-check" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $term === null ? 'No hay un periodo activo.' : "{$term->name} no está abierto en ningún campus." }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">La validación se abre desde aquí cuando el periodo está abierto en un campus (Periodos); mientras no, no hay nada que seguir.</p>
        </div>
    @elseif ($board['columns'] === [])
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="validation-not-open">
            <x-icon name="o-lock-closed" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">La validación de este periodo no está abierta</p>
            @if ($canOpen)
                <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">Elige qué catálogos validan los jefes de departamento en {{ $term->name }}: ninguno, algunos o todos. Puedes abrir otros después.</p>
                <x-button icon="o-lock-open" class="btn-primary mt-4" wire:click="startOpening" data-testid="open-validation-empty">Abrir validación</x-button>
            @else
                <p class="mx-auto mt-1 max-w-prose text-sm text-neutral" data-testid="not-open-reason">Solo el administrador abre la validación de un periodo y decide qué catálogos se validan. Cuando la abra, aquí verás el avance.</p>
            @endif
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
                <x-button icon="o-bell" class="btn-primary" wire:click="remind" spinner="remind" data-testid="remind-all" :disabled="$board['remindable']['departments'] === 0" :title="$board['remindable']['departments'] === 0 ? 'Ningún departamento visible tiene jefe con catálogos pendientes.' : null">
                    Recordar a todos los pendientes
                    <span class="badge badge-sm badge-neutral" aria-hidden="true" data-testid="remind-all-badge">{{ $pendingHeads }}</span>
                    <span class="sr-only" data-testid="remind-all-count">{{ $pendingHeads }} {{ $pendingHeads === 1 ? 'jefe por recordar' : 'jefes por recordar' }}</span>
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
                @php($columns = $board['columns'])
                <div class="hidden overflow-x-auto lg:block" data-testid="progress-scroll">
                    <table class="w-full min-w-[56rem] text-sm">
                        <caption class="sr-only">Estado de cada catálogo por departamento</caption>
                        <thead class="bg-base-200 text-start">
                            <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                <th scope="col" class="sticky left-0 z-10 w-52 min-w-52 max-w-52 bg-base-200 px-4 py-2.5 text-start font-medium">Departamento</th>
                                @foreach ($columns as $column)
                                    <th scope="col" class="px-2 py-2.5 text-start font-medium" data-testid="column-{{ $column['key'] }}">{{ $column['catalog']->label() }}</th>
                                @endforeach
                                <th scope="col" class="whitespace-nowrap px-1.5 py-2.5 text-start font-medium">Validados</th>
                                <th scope="col" class="px-2 py-2.5 text-start font-medium">Obs.</th>
                                <th scope="col" class="w-24 px-1.5 py-2.5"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            @foreach ($board['rows'] as $row)
                                @php($department = $row['department'])
                                <tr wire:key="row-{{ $department->id }}" class="align-top" data-testid="progress-row-{{ $department->code }}">
                                    <td class="sticky left-0 z-10 w-52 min-w-52 max-w-52 bg-base-100 px-4 py-2.5">
                                        @include('catalog-validation::progress.who', ['row' => $row, 'campuses' => $campuses])
                                    </td>
                                    @foreach ($columns as $column)
                                        <td class="px-2 py-2.5" data-testid="cell-{{ $department->code }}-{{ $column['key'] }}">@include('catalog-validation::progress.cell', ['review' => $row['cells'][$column['key']], 'reviewed' => $row['reviewed'][$column['key']]])</td>
                                    @endforeach
                                    <td class="px-2 py-2.5 whitespace-nowrap"><span class="font-semibold tabular-nums">{{ $row['validated'] }} de {{ $row['total'] }}</span></td>
                                    <td class="px-2 py-2.5">@include('catalog-validation::progress.flags', ['row' => $row])</td>
                                    <td class="px-2 py-2">
                                        <div class="flex items-center justify-end gap-1">
                                            @include('catalog-validation::progress.actions', ['row' => $row, 'inTable' => true])
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-base-300 bg-base-200/60" data-testid="progress-totals">
                            <tr class="align-top">
                                <th scope="row" class="sticky left-0 z-10 bg-base-200 px-4 py-2.5 text-start font-semibold">Total del Tec<span class="block text-xs font-normal text-neutral">{{ count($board['rows']) }} {{ count($board['rows']) === 1 ? 'departamento' : 'departamentos' }}</span></th>
                                @foreach ($columns as $column)
                                    @php($totals = $board['totals'][$column['key']])
                                    <td class="px-2 py-2.5" data-testid="total-{{ $column['key'] }}">
                                        <span class="whitespace-nowrap tabular-nums">{{ $totals['validated'] }} de {{ $totals['total'] }}</span>
                                        <i class="mt-1 block h-1 overflow-hidden rounded-full bg-base-300" aria-hidden="true"><b class="block h-full bg-success" style="width: {{ $totals['total'] === 0 ? 0 : $totals['validated'] / $totals['total'] * 100 }}%"></b></i>
                                    </td>
                                @endforeach
                                <td class="px-2 py-2.5 whitespace-nowrap"><span class="font-semibold tabular-nums">{{ array_sum(array_column($board['rows'], 'validated')) }} de {{ array_sum(array_column($board['rows'], 'total')) }}</span></td>
                                <td class="px-2 py-2.5 font-mono font-semibold tabular-nums">{{ array_sum(array_column($board['rows'], 'open_flags')) }}</td>
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
                            @if ($row['pending_columns'] === [])
                                <p class="text-sm text-neutral">Todo validado</p>
                            @else
                                <dl class="grid grid-cols-[7.5rem_1fr] items-center gap-x-3 gap-y-1.5 text-sm">
                                    @foreach ($row['pending_columns'] as $column)
                                        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $column['catalog']->label() }}</dt>
                                        <dd>@include('catalog-validation::progress.cell', ['review' => $row['cells'][$column['key']], 'reviewed' => $row['reviewed'][$column['key']], 'showDate' => true])</dd>
                                    @endforeach
                                </dl>
                            @endif
                            @if ($row['open_flags'] > 0)
                                <p class="text-sm text-neutral">Observaciones abiertas: @include('catalog-validation::progress.flags', ['row' => $row])</p>
                            @endif
                            <div class="flex items-center justify-between gap-2">
                                @include('catalog-validation::progress.actions', ['row' => $row, 'inTable' => false])
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 border-t border-base-300 px-4 py-2.5 text-sm text-neutral" data-testid="progress-legend">
                    <p>{{ count($board['rows']) }} {{ count($board['rows']) === 1 ? 'departamento' : 'departamentos' }}</p>
                    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 max-lg:hidden">
                        <span class="inline-flex items-center gap-1"><x-icon name="o-check-circle" class="h-4 w-4" aria-hidden="true" />Validado</span>
                        <span class="inline-flex items-center gap-1"><x-icon name="o-eye" class="h-4 w-4" aria-hidden="true" />En revisión</span>
                        <span class="inline-flex items-center gap-1"><x-icon name="o-clock" class="h-4 w-4" aria-hidden="true" />Pendiente</span>
                    </p>
                </div>
            @endif
        </div>
    @endif

    @if ($canOpen)
        <x-drawer wire:model="showOpen" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="open-drawer-title" data-testid="open-drawer">
            <form wire:submit="openValidation" class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="open-drawer-title" class="text-xl font-bold leading-tight">Abrir validación</h2>
                        <p class="mt-1 text-sm text-neutral">{{ $term?->name }}</p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
                </header>
                <fieldset class="flex-1 space-y-1 px-5 pt-4 pb-8">
                    <legend class="mb-2 text-sm text-neutral">Los jefes de cada departamento revisan y validan los catálogos que abras. Un catálogo abierto no se quita del periodo.</legend>
                    @foreach ($options as $option)
                        <div class="rounded-box border border-base-300 px-3 py-2.5" data-testid="open-option-{{ $option['catalog']->value }}">
                            @if ($option['open'])
                                <x-checkbox :label="$option['catalog']->label()" checked disabled />
                                <p class="ms-8 text-sm text-neutral">Ya está abierto.</p>
                            @else
                                <x-checkbox :label="$option['catalog']->label()" value="{{ $option['catalog']->value }}" wire:model="toOpen" :disabled="$option['blocked'] !== null" omit-error />
                                @if ($option['blocked'])
                                    <p class="ms-8 text-sm text-neutral">{{ $option['blocked'] }}</p>
                                @endif
                            @endif
                        </div>
                    @endforeach
                    @error('toOpen')<p class="pt-1 text-sm text-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>
                <footer class="flex justify-end gap-2 border-t border-base-300 px-5 py-3">
                    <x-button label="Cancelar" class="btn-ghost" @click="close()" />
                    <x-button label="Abrir" icon="o-lock-open" class="btn-primary" type="submit" spinner="openValidation" data-testid="open-submit" />
                </footer>
            </form>
        </x-drawer>
    @endif

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="progress-drawer-title" data-testid="progress-drawer">
        @if ($detail !== null)
            @include('catalog-validation::progress.drawer', ['row' => $detail['row'], 'flags' => $detail['flags'], 'columns' => $board['columns'] ?? [], 'term' => $term])
        @endif
    </x-drawer>
</div>
