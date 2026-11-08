{{--
    La semana (DESIGN.md, "Cuadrícula día x hora" y "Editor de horarios"), desde 768 px: una columna por día del perfil
    del campus, tan ancha como sus carriles (`--cols`), y un renglón de 3.25rem por bloque. El encabezado de cada día
    lleva su nombre y sus horas ocupadas, y desde 1200 px se queda pegado bajo la banda; antes, la caja se desplaza de
    lado (nunca la página). Cada columna lleva sus renglones (o, con un grupo elegido, sus celdas), las bandas de la
    disponibilidad del docente o de los bloqueos del salón y las sesiones (scheduling::editor.session).

    Con un grupo elegido cada celda dice si cabe (`fits`, `success/15` y «+»), si choca con una regla que se guarda
    marcada (`clash`, rayas `error` con su regla) o si no se puede (`blocked`, HC-01 o HC-02: rayas neutras, sin
    botón, con el motivo en `title` y para lectores de pantalla). Quien puede acomodar el grupo coloca tocando una
    celda que cabe o choca; quien no, ve lo mismo sin botones. Las celdas van sobre las sesiones de los demás grupos,
    que se atenúan; las del grupo elegido quedan a la vista y se abren.
--}}
@php
    $dayVerb = fn (array $day): string => mb_strtolower($day['name']);
    $title = $selected ? $selected['subject']['code'].' · '.$selected['code'] : '';
    $headBg = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]';
@endphp
<div class="max-md:hidden max-desktop:overflow-x-auto max-desktop:pb-1.5" data-testid="week">
    <div class="grid grid-cols-[3.25rem_var(--cols)] overflow-clip rounded-box border border-base-300 bg-base-100 select-none max-desktop:min-w-[calc(3.25rem+var(--units)*7rem)]"
        style="--cols: {{ implode(' ', array_map(fn (int $lanes): string => "minmax(0,{$lanes}fr)", $lanes)) }}; --units: {{ array_sum($lanes) }}"
        role="group" aria-label="Semana de {{ $grid->days[0]['name'] ?? '' }} a {{ mb_strtolower(array_last($grid->days)['name'] ?? '') }}"
        x-on:keydown="key($event)" x-on:focusin="rove($event)">
        <div class="{{ $headBg }} border-b border-base-300 desktop:sticky desktop:top-(--shell-top) desktop:z-10"></div>
        @foreach ($grid->days as $day)
            <div class="{{ $headBg }} flex min-w-0 flex-col justify-center border-b border-l border-base-300 px-2.5 py-1.5 desktop:sticky desktop:top-(--shell-top) desktop:z-10">
                <b class="truncate font-semibold">{{ $day['name'] }}</b>
                <span class="truncate font-mono text-xs text-neutral">{{ $busy[$day['day']] }} h ocupadas</span>
            </div>
        @endforeach

        <div aria-hidden="true">
            @foreach ($grid->blocks as $h => $block)
                <div class="h-13 pe-2 pt-1 text-end font-mono text-xs text-neutral tabular-nums">{{ $block['label'] }}</div>
            @endforeach
        </div>

        @foreach ($grid->days as $index => $day)
            <div class="relative min-w-0 border-l border-base-300" role="group" aria-label="{{ $day['name'] }}" wire:key="day-{{ $day['day'] }}">
                @foreach ($grid->blocks as $h => $block)
                    @php
                        $cell = $cells[$day['day']][$h] ?? null;
                    @endphp
                    @if ($cell === null || $cell['state'] === 'own')
                        <div @class(['h-13', 'border-t border-base-300' => $h > 0])></div>
                    @else
                        @php
                            $why = implode(' ', $cell['rules']).($cell['messages'] !== [] ? ' · '.implode(' ', $cell['messages']) : '');
                            $where = $dayVerb($day).' de '.$block['label'].' a '.\App\Modules\Scheduling\Support\WeekGrid::clock($block['ends_at']);
                            $classes = [
                                'relative z-[3] mx-px my-px flex h-[calc(3.25rem-2px)] items-end justify-end gap-1 rounded-selector p-1 font-mono text-[11.5px] font-semibold',
                                $fills[$cell['state']],
                                $canPlace && $cell['state'] !== 'blocked' ? 'w-[calc(100%-2px)] cursor-pointer hover:brightness-95 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary' : '',
                                $cell['state'] === 'fits' && $canPlace ? 'hover:bg-success/30' : '',
                            ];
                        @endphp
                        @if ($canPlace && $cell['state'] !== 'blocked')
                            <button type="button" @class($classes) data-cell="{{ $cell['key'] }}" data-fit="{{ $cell['state'] }}" wire:key="cell-{{ $cell['key'] }}"
                                data-nav data-x="{{ $index + 0.5 }}" data-r0="{{ $h }}" data-r1="{{ $h + 1 }}" tabindex="{{ $tabStop === 'cell:'.$cell['key'] ? 0 : -1 }}"
                                wire:click="place('{{ $selected['id'] }}', {{ $day['day'] }}, '{{ $block['starts_at'] }}')"
                                aria-label="Colocar {{ $title }} el {{ $where }}{{ $cell['state'] === 'clash' ? ', con choque '.$why : '' }}"
                                @if ($cell['state'] === 'clash') title="Choca: {{ $why }}" @endif>
                        @else
                            <div @class($classes) data-cell="{{ $cell['key'] }}" data-fit="{{ $cell['state'] }}" wire:key="cell-{{ $cell['key'] }}"
                                @if ($cell['state'] !== 'fits') title="{{ $cell['state'] === 'blocked' ? 'No se puede' : 'Choca' }}: {{ $why }}" @endif>
                                <span class="sr-only">{{ ucfirst($where) }}: {{ match ($cell['state']) { 'fits' => 'cabe', 'clash' => 'choca, '.$why, default => 'no se puede, '.$why } }}</span>
                        @endif
                            @if ($cell['state'] === 'fits')
                                <x-icon name="o-plus" class="h-4 w-4" aria-hidden="true" />
                            @else
                                <span aria-hidden="true">{{ implode(' ', $cell['rules']) }}</span>
                                <x-icon :name="$cell['state'] === 'clash' ? 'o-exclamation-triangle' : 'o-no-symbol'" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            @endif
                        @if ($canPlace && $cell['state'] !== 'blocked')
                            </button>
                        @else
                            </div>
                        @endif
                    @endif
                @endforeach

                @foreach ($bands[$day['day']] ?? [] as $band)
                    @php
                        $position = $grid->position($band['starts_at'], $band['ends_at']);
                    @endphp
                    <div @class([
                        'pointer-events-none absolute inset-x-0 top-[calc(var(--top)*3.25rem)] z-[1] flex h-[calc(var(--span)*3.25rem)] flex-col gap-px overflow-hidden px-2 py-1 text-xs',
                        $band['kind'] === 'preferred' ? $fills['preferred'].' justify-end' : $fills['blocked'],
                    ]) style="--top: {{ $position['top'] }}; --span: {{ $position['span'] }}">
                        <b class="flex items-center gap-1 font-medium"><x-icon :name="match ($band['kind']) { 'preferred' => 'o-star', 'blocked' => 'o-lock-closed', default => 'o-no-symbol' }" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $band['label'] }}</b>
                        <span class="font-mono text-neutral">{{ $band['hours'] }}</span>
                        @if (! empty($band['reason']))
                            <span class="truncate text-neutral">{{ $band['reason'] }}</span>
                        @endif
                    </div>
                @endforeach

                @foreach ($laid[$day['day']] as $item)
                    <x-scheduling::editor.session :item="$item" :chain-classes="$chainClasses" :day-index="$index" :tab-stop="$tabStop" />
                @endforeach
            </div>
        @endforeach
    </div>
</div>
