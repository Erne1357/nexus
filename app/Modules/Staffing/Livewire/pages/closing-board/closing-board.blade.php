{{--
    Avance del cierre (tarea 4.2; DESIGN.md, «Avance del cierre»: maqueta E, elegida por el usuario el 10 de noviembre de
    2026), con la forma del avance de validación: el periodo y cuántos departamentos y cargas; cuatro cifras de todo el
    Tec; la búsqueda, el filtro de estado y cuándo se recalcularon los choques; y la matriz, una tabla desde 1024 px y,
    antes, renglones apilados con las mismas cifras. Un renglón por departamento con una insignia por carga (código,
    icono y cifra) y los faltantes sumados por columna; uno sin grupos en ningún campus va al final, atenuado. Tocar un
    renglón o una insignia abre el drawer de esa carga.
--}}
<div>
    <x-header title="Avance del cierre" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-4!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="text-sm text-neutral!">
            @if ($board)
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5" data-testid="board-meta">
                    <span>{{ $term->name }}</span><span aria-hidden="true">·</span>
                    <span>{{ $board['departments'] }} {{ $board['departments'] === 1 ? 'departamento' : 'departamentos' }} · {{ $board['kpis']['total'] }} {{ $board['kpis']['total'] === 1 ? 'carga' : 'cargas' }}@if ($board['campuses']->isNotEmpty()) en @foreach ($board['campuses'] as $campus)<span class="font-mono font-semibold text-base-content">{{ $campus->code }}</span>@if (! $loop->last){{ $loop->remaining === 1 ? ' y ' : ', ' }}@endif @endforeach @endif</span>
                </span>
            @else
                Cuánto le falta a cada carga del Tec para cerrar.
            @endif
        </x-slot:subtitle>
    </x-header>

    @if ($board === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="board-empty">
            <x-icon name="o-lock-closed" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">No hay un periodo activo.</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-neutral">Elige uno en tu credencial, arriba a la derecha.</p>
        </div>
    @else
        @php($kpis = $board['kpis'])
        <section class="mb-4 grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:grid-cols-4" aria-label="Cargas de todo el Tec por estado; cada cifra filtra la matriz" data-testid="board-kpis">
            {{-- Cada cifra filtra la matriz por su cubeta (como «Estado», en `?estado=`); la elegida, en gris polvo con su filete, y otra vez la quita. --}}
            @foreach ([
                ['missing', 'faltantes', 'Cargas con faltantes', 'text-warning'],
                ['ready', 'listas', 'Listas para cerrar', ''],
                ['closed', 'cerradas', 'Cerradas, falta publicar', 'text-info'],
                ['published', 'publicadas', 'Publicadas', 'text-success'],
            ] as [$key, $value, $label, $tone])
                @php($pressed = $state === $value)
                <button type="button" wire:click="filterBy('{{ $value }}')" aria-pressed="{{ $pressed ? 'true' : 'false' }}"
                    @class([
                        'cursor-pointer border-base-300 p-4 text-start not-first:border-s hover:bg-base-200/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary max-lg:[&:nth-child(3)]:border-s-0 max-lg:[&:nth-child(n+3)]:border-t',
                        'bg-secondary/50 shadow-[inset_0_-3px_0_0_var(--color-primary)] hover:bg-secondary/50' => $pressed,
                    ]) data-testid="kpi-{{ $key }}">
                    <span class="flex items-baseline gap-1.5"><span @class(['font-mono text-[26px] leading-tight font-semibold tabular-nums', $tone => $kpis[$key] > 0])>{{ $kpis[$key] }}</span> <span class="text-sm text-neutral">de <span class="font-mono tabular-nums">{{ $kpis['total'] }}</span></span></span>
                    <span @class(['block text-sm', 'font-semibold text-base-content' => $pressed, 'text-neutral' => ! $pressed])>{{ $label }}</span>
                </button>
            @endforeach
        </section>

        <x-core::catalog-toolbar>
            <x-slot:search>
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Clave o departamento" aria-label="Buscar por clave o departamento" clearable data-testid="board-search" />
            </x-slot:search>
            <x-select wire:model.live="state" aria-label="Estado de alguna de sus cargas" :options="[['id' => 'faltantes', 'name' => 'Con faltantes'], ['id' => 'listas', 'name' => 'Listas para cerrar'], ['id' => 'cerradas', 'name' => 'Cerradas'], ['id' => 'publicadas', 'name' => 'Publicadas']]" placeholder="Estado" placeholder-value="" data-testid="board-state" />
            <x-slot:action>
                <p class="flex items-center gap-1.5 text-sm text-neutral md:min-h-10" data-testid="synced-at">
                    <x-icon name="o-arrow-path" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    @if ($board['syncedAt'])
                        <span>Choques recalculados {{ $board['syncedAt']->isToday() ? 'hoy a las '.$board['syncedAt']->format('G:i') : 'el '.$board['syncedAt']->translatedFormat('j M, G:i') }}@if ($board['unsynced'] !== []) · {{ implode(', ', $board['unsynced']) }} sin recalcular @endif</span>
                    @else
                        <span>Choques sin recalcular todavía</span>
                    @endif
                </p>
            </x-slot:action>
        </x-core::catalog-toolbar>

        @php($rows = $board['rows'])
        @php($totals = $board['totals'])
        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="board-matrix">
            @if ($rows === [])
                <p class="px-5 py-8 text-center font-semibold">Ningún departamento con ese filtro.</p>
            @else
                <div class="relative hidden overflow-x-auto lg:block">
                    <table class="w-full min-w-[60rem] text-sm">
                        <caption class="sr-only">Lo que le falta a cada carga para cerrar, por departamento</caption>
                        <thead class="bg-base-200">
                            <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                <th scope="col" class="sticky left-0 z-10 min-w-52 bg-base-200 px-4 py-2.5 text-start font-medium">Departamento</th>
                                <th scope="col" class="min-w-64 px-2 py-2.5 text-start font-medium">Campus</th>
                                <th scope="col" class="w-24 px-2 py-2.5 text-center font-medium">Choques duros</th>
                                <th scope="col" class="w-24 px-2 py-2.5 text-center font-medium">Sin horario</th>
                                <th scope="col" class="w-24 px-2 py-2.5 text-center font-medium">Sin docente</th>
                                <th scope="col" class="w-24 px-2 py-2.5 text-center font-medium">Sin salón</th>
                                <th scope="col" class="w-24 px-2 py-2.5 text-center font-medium" title="Docente dado de baja, solicitudes de cambio y acuerdos">Otros</th>
                                <th scope="col" class="w-28 px-4 py-2.5 text-end font-medium">Justificados</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            @foreach ($rows as $row)
                                @php($department = $row['department'])
                                @if ($row['loads'] === [])
                                    <tr wire:key="board-row-{{ $department->id }}" class="text-neutral" data-testid="board-row-{{ $department->code }}">
                                        <th scope="row" class="sticky left-0 z-10 bg-base-100 px-4 py-2.5 text-start font-normal">
                                            <span class="me-2 font-mono font-semibold">{{ $department->code }}</span><span class="font-medium">{{ $department->name }}</span>
                                            <span class="block text-xs">Sin grupos en ningún campus</span>
                                        </th>
                                        <td colspan="7"></td>
                                    </tr>
                                @else
                                    @php($open = $showDrawer && $closing !== null && $closing->load->department_id === $department->id)
                                    <tr wire:key="board-row-{{ $department->id }}" wire:click="open('{{ $department->id }}')" @class(['group cursor-pointer hover:bg-base-200/50', 'bg-secondary/40' => $open]) data-testid="board-row-{{ $department->code }}">
                                        {{-- La columna del departamento queda fija al desplazar la matriz de lado (como en «Avance de validación»); su fondo es opaco y repite el del renglón. --}}
                                        <th scope="row" @class(['sticky left-0 z-10 px-4 py-2.5 text-start font-normal', 'bg-base-100 group-hover:bg-[color-mix(in_oklab,var(--color-base-200)_50%,var(--color-base-100))]' => ! $open, 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]' => $open])>
                                            <button type="button" wire:click.stop="open('{{ $department->id }}')" class="group text-start" aria-label="Abrir el detalle de {{ $department->name }}">
                                                <span class="me-2 font-mono font-semibold">{{ $department->code }}</span><span class="font-semibold underline decoration-transparent underline-offset-4 group-hover:decoration-current group-focus-visible:decoration-current">{{ $department->name }}</span>
                                            </button>
                                            <span class="block text-xs text-neutral"><span class="font-mono tabular-nums">{{ $row['groups'] }}</span> {{ $row['groups'] === 1 ? 'grupo' : 'grupos' }} · @include('staffing::closing.head', ['names' => $heads[$department->id] ?? []])</span>
                                        </th>
                                        <td class="px-2 py-2.5"><span class="flex flex-wrap gap-1.5">@foreach ($row['loads'] as $progress)@include('staffing::closing.campus-badge', ['progress' => $progress, 'selected' => $open && $closing->load->id === $progress->load->id])@endforeach</span></td>
                                        <td class="px-2 py-2.5 text-center">@include('staffing::closing.count', ['kind' => 'hard', 'n' => $row['hard']])</td>
                                        <td class="px-2 py-2.5 text-center">@include('staffing::closing.count', ['kind' => 'unscheduled', 'n' => $row['unscheduled']])</td>
                                        <td class="px-2 py-2.5 text-center">@include('staffing::closing.count', ['kind' => 'teacher', 'n' => $row['withoutTeacher']])</td>
                                        <td class="px-2 py-2.5 text-center">@include('staffing::closing.count', ['kind' => 'room', 'n' => $row['withoutRoom']])</td>
                                        <td class="px-2 py-2.5 text-center">@include('staffing::closing.count', ['kind' => 'others', 'n' => $row['others']])</td>
                                        <td class="px-4 py-2.5 text-end font-mono tabular-nums">@if ($row['justified'] > 0){{ $row['justified'] }}@else<span class="text-neutral/60" aria-hidden="true">–</span><span class="sr-only">Ninguno</span>@endif</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-base-300 bg-base-200/60" data-testid="board-totals">
                            <tr class="font-semibold">
                                <th scope="row" class="sticky left-0 z-10 bg-[color-mix(in_oklab,var(--color-base-200)_60%,var(--color-base-100))] px-4 py-2.5 text-start">Todo el Tec · <span class="font-mono tabular-nums">{{ $totals['groups'] }}</span> grupos</th>
                                <td class="px-2 py-2.5">{{ $totals['loads'] }} {{ $totals['loads'] === 1 ? 'carga' : 'cargas' }}</td>
                                @foreach (['hard', 'unscheduled', 'withoutTeacher', 'withoutRoom', 'others'] as $column)
                                    <td class="px-2 py-2.5 text-center font-mono tabular-nums">{{ $totals[$column] ?: '–' }}</td>
                                @endforeach
                                <td class="px-4 py-2.5 text-end font-mono tabular-nums">{{ $totals['justified'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <ul class="divide-y divide-base-300 lg:hidden" data-testid="board-cards">
                    @foreach ($rows as $row)
                        @php($department = $row['department'])
                        <li wire:key="board-card-{{ $department->id }}" @class(['space-y-2 px-4 py-3', 'text-neutral' => $row['loads'] === []])>
                            @if ($row['loads'] === [])
                                <p><span class="me-1.5 font-mono font-semibold">{{ $department->code }}</span>{{ $department->name }}</p>
                                <p class="text-xs">Sin grupos en ningún campus</p>
                            @else
                                {{-- Las insignias de campus siempre bajo el nombre en celular, junto a él desde 640 px. --}}
                                <div class="flex flex-col items-start gap-x-3 gap-y-1 sm:flex-row sm:flex-wrap sm:justify-between">
                                    <div class="min-w-0">
                                        <button type="button" wire:click="open('{{ $department->id }}')" class="min-h-11 min-w-0 text-start font-semibold" aria-label="Abrir el detalle de {{ $department->name }}">
                                            <span class="me-1.5 font-mono">{{ $department->code }}</span>{{ $department->name }}
                                        </button>
                                        <p class="-mt-1.5 text-xs text-neutral">@include('staffing::closing.head', ['names' => $heads[$department->id] ?? []])</p>
                                    </div>
                                    <span class="flex flex-wrap gap-1.5 sm:pt-2">@foreach ($row['loads'] as $progress)@include('staffing::closing.campus-badge', ['progress' => $progress, 'selected' => false, 'testid' => 'card-badge'])@endforeach</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="badge badge-neutral badge-soft h-6.5"><span class="font-mono">{{ $row['groups'] }}</span> {{ $row['groups'] === 1 ? 'grupo' : 'grupos' }}</span>
                                    @foreach (['hard' => $row['hard'], 'unscheduled' => $row['unscheduled'], 'teacher' => $row['withoutTeacher'], 'room' => $row['withoutRoom'], 'others' => $row['others']] as $kind => $n)
                                        @if ($n > 0)@include('staffing::closing.count', ['kind' => $kind, 'n' => $n, 'labelled' => true])@endif
                                    @endforeach
                                    @if ($row['justified'] > 0)
                                        <span class="badge badge-neutral badge-soft h-6.5"><span class="font-mono">{{ $row['justified'] }}</span> {{ $row['justified'] === 1 ? 'justificado' : 'justificados' }}</span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral" aria-label="Leyenda" data-testid="board-legend">
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="warning" icon="o-exclamation-triangle" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span aria-hidden="true">·</span> 21</x-core::status-badge>Con faltantes</span>
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="error" icon="o-x-circle" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span aria-hidden="true">·</span> 21</x-core::status-badge>Con algún choque duro</span>
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="info" icon="o-arrow-path-rounded-square" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5" />En propuesta o ajustes, sin faltantes</span>
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="success" icon="o-check" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5" />Lista para cerrar</span>
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="success" icon="o-lock-closed" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5" />Cerrada, falta publicar</span>
                <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="success-solid" icon="o-check-circle" label="C1" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span aria-hidden="true">·</span> v2</x-core::status-badge>Publicada</span>
            </div>
        </div>
    @endif

    <x-drawer wire:model.live="showDrawer" right @keydown.window.escape="if (! document.querySelector('.modal.modal-open')) close()" class="w-full max-w-2xl p-0!" role="dialog" aria-modal="true" aria-labelledby="load-drawer-title" data-testid="board-drawer">
        @if ($closing)
            @include('staffing::closing.load-drawer', ['closing' => $closing, 'tabs' => $tabs, 'actions' => $actions, 'canViewLoad' => $canViewLoad])
        @endif
    </x-drawer>

    @include('staffing::closing.modals', ['closing' => $closing])
</div>
