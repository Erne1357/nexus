{{--
    La semana (DESIGN.md, "Cuadrícula día x hora" y "Editor de horarios"), desde 768 px: una columna por día del perfil
    del campus, tan ancha como sus carriles (`--cols`), y un renglón de 3.25rem por bloque. Cada carril mide al menos
    `--lane-min` (un día sin sesiones, un carril; `--lane` es el carril de cada sesión), así «CLAVE · grupo» se lee aunque el día tenga varios carriles; lo que
    no cabe se ve desplazando de lado la caja (nunca la página), que mide a lo más el alto de la ventana bajo la banda.
    Al desplazarla, la columna de horas y el encabezado de los días (su nombre y sus horas ocupadas) se quedan pegados,
    y `scroll-padding` deja lo que toma el foco fuera de ellos. Cada columna lleva sus renglones (o, con un grupo
    elegido, sus celdas), las bandas de la disponibilidad del docente o de los bloqueos del salón y las sesiones
    (scheduling::editor.session).

    Con un grupo elegido cada celda dice si cabe (`fits`, `success/15` y «+»), si choca con una regla que se guarda
    marcada (`clash`, rayas `error` con su regla) o si no se puede (`blocked`, HC-01 o HC-02: rayas neutras). Quien
    puede acomodar el grupo coloca tocando una celda que cabe o choca; la que no se puede es un botón deshabilitado
    (`aria-disabled`) que las flechas también recorren y que deja pasar los clics a la sesión de abajo, salvo su
    marca (la regla y el icono, `data-why-target`), que muestra el motivo bajo el puntero. El motivo de las que chocan o no se pueden va en
    `aria-describedby` y, con el foco o bajo el puntero, en un aviso junto a la celda (`data-why`, `explain()` de
    `scheduleEditor`). Quien no acomoda el grupo ve lo mismo sin botones, con el motivo para lectores de pantalla y sin
    tapar los clics a las sesiones (`pointer-events-none`). Las celdas van sobre las sesiones de los demás grupos, que se atenúan; las del grupo
    elegido quedan a la vista y se abren.
--}}
@php
    $dayVerb = fn (array $day): string => mb_strtolower($day['name']);
    $title = $selected ? $selected['subject']['code'].' · '.$selected['code'] : '';
    $headBg = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]';
@endphp
<div id="editor-week" tabindex="-1" x-on:scroll="placeTip()" class="max-h-[calc(100dvh-var(--shell-top)-2rem)] scroll-pt-13 scroll-ps-13 overflow-auto rounded-box [scrollbar-color:var(--color-base-300)_transparent] [scrollbar-width:thin] border border-base-300 bg-base-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary max-md:hidden" data-testid="week">
    <div class="grid min-w-[calc(3.25rem+var(--units)*var(--lane-min))] grid-cols-[3.25rem_var(--cols)] select-none [--lane-min:7rem]"
        style="--cols: {{ implode(' ', array_map(fn (int $lanes): string => "minmax(calc({$lanes}*var(--lane-min)),{$lanes}fr)", $lanes)) }}; --units: {{ array_sum($lanes) }}"
        role="group" aria-label="Semana de {{ $grid->days[0]['name'] ?? '' }} a {{ mb_strtolower(array_last($grid->days)['name'] ?? '') }}"
        x-on:keydown="key($event)" x-on:focusin="rove($event); explain($event)" x-on:focusout="explain({ target: $event.relatedTarget ?? document.body })"
        x-on:mouseover="explain($event)" x-on:mouseleave="explain({ target: document.activeElement })">
        <div class="{{ $headBg }} sticky top-0 left-0 z-[7] border-e border-b border-base-300"></div>
        @foreach ($grid->days as $index => $day)
            <div @class([$headBg, 'sticky top-0 z-[6] flex min-w-0 flex-col justify-center border-b border-base-300 px-2 py-1.5', 'border-l' => $index > 0])>
                <b class="truncate font-semibold">{{ $day['name'] }}</b>
                <span class="text-xs leading-tight text-neutral"><span class="font-mono">{{ $busy[$day['day']] }}</span> h ocupadas</span>
            </div>
        @endforeach

        <div class="sticky left-0 z-[5] border-e border-base-300 bg-base-100" aria-hidden="true">
            @foreach ($grid->blocks as $h => $block)
                <div class="h-13 pe-2 pt-1 text-end font-mono text-xs text-neutral tabular-nums">{{ $block['label'] }}</div>
            @endforeach
        </div>

        @foreach ($grid->days as $index => $day)
            <div @class(['relative min-w-0 border-base-300', 'border-l' => $index > 0]) role="group" aria-label="{{ $day['name'] }}" wire:key="day-{{ $day['day'] }}">
                @foreach ($grid->blocks as $h => $block)
                    @php
                        $cell = $cells[$day['day']][$h] ?? null;
                    @endphp
                    @if ($cell === null || $cell['state'] === 'own')
                        <div @class(['h-13', 'border-t border-base-300' => $h > 0])></div>
                    @else
                        @php
                            $why = $cell['why'];
                            $where = $dayVerb($day).' de '.$block['label'].' a '.\App\Modules\Core\Support\WeekGrid::clock($block['ends_at']);
                            $reason = match ($cell['state']) { 'clash' => 'Choca: '.$why, 'blocked' => 'No se puede: '.$why, default => null };
                            $classes = [
                                'relative z-[3] mx-px my-px flex h-[calc(3.25rem-2px)] items-end justify-end gap-1 rounded-selector p-1 font-mono text-[11.5px] font-semibold',
                                $fills[$cell['state']],
                                $canPlace ? 'w-[calc(100%-2px)] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary' : '',
                                $canPlace && $cell['state'] !== 'blocked' ? 'cursor-pointer hover:brightness-95' : '',
                                $canPlace && $cell['state'] === 'blocked' ? 'pointer-events-none' : '',
                                $cell['state'] === 'fits' && $canPlace ? 'hover:bg-success/30' : '',
                            ];
                        @endphp
                        @if ($canPlace)
                            <button type="button" @class($classes) data-cell="{{ $cell['key'] }}" data-fit="{{ $cell['state'] }}" wire:key="cell-{{ $cell['key'] }}"
                                data-nav data-x="{{ $index + 0.5 }}" data-r0="{{ $h }}" data-r1="{{ $h + 1 }}" tabindex="{{ $tabStop === 'cell:'.$cell['key'] ? 0 : -1 }}"
                                @if ($cell['state'] === 'blocked')
                                    aria-disabled="true" aria-label="No se puede colocar {{ $title }} el {{ $where }}"
                                @else
                                    wire:click="place('{{ $selected['id'] }}', {{ $day['day'] }}, '{{ $block['starts_at'] }}')" aria-label="Colocar {{ $title }} el {{ $where }}"
                                @endif
                                @if ($reason) data-why="{{ $reason }}" aria-describedby="why-{{ $cell['key'] }}" @endif>
                                @if ($reason)
                                    <span id="why-{{ $cell['key'] }}" class="sr-only">{{ $reason }}</span>
                                @endif
                        @else
                            <div @class([...$classes, 'pointer-events-none']) data-cell="{{ $cell['key'] }}" data-fit="{{ $cell['state'] }}" wire:key="cell-{{ $cell['key'] }}">
                                <span class="sr-only">{{ ucfirst($where) }}: {{ match ($cell['state']) { 'fits' => 'cabe', 'clash' => 'choca, '.$why, default => 'no se puede, '.$why } }}</span>
                        @endif
                            @if ($cell['state'] === 'fits')
                                <x-icon name="o-plus" class="h-4 w-4" aria-hidden="true" />
                            @else
                                <span @class(['inline-flex items-center gap-1', 'pointer-events-auto cursor-not-allowed' => $canPlace && $cell['state'] === 'blocked']) @if ($canPlace && $cell['state'] === 'blocked') data-why-target @endif aria-hidden="true">
                                    {{ implode(' ', $cell['rules']) }}
                                    <x-icon :name="$cell['state'] === 'clash' ? 'o-exclamation-triangle' : 'o-no-symbol'" class="h-4 w-4 shrink-0" />
                                </span>
                            @endif
                        @if ($canPlace)
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

{{-- El motivo de la celda con el foco o bajo el puntero (`data-why`), junto a ella y fuera de la caja para que no lo recorte. --}}
<div x-cloak x-show="tip.text !== ''" x-text="tip.text" aria-hidden="true" data-testid="week-tip"
    x-bind:style="`left: ${tip.left}px; top: ${tip.top}px; transform: translateY(${tip.above ? '-100%' : '0'})`"
    class="pointer-events-none fixed z-20 max-w-80 rounded-field bg-neutral px-2.5 py-1.5 text-sm leading-snug text-neutral-content shadow-float max-md:hidden"></div>
