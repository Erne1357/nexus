{{--
    Cuadrícula día × hora de la disponibilidad de un docente (DESIGN.md, "Cuadrícula día x hora"), la misma en la
    pestaña de la ficha (de solo lectura) y en el editor (`$interactive`). `$grid` sale de AvailabilityGridQuery: una
    columna por día del perfil y un renglón por bloque, de 36 px. Cada celda lleva su tipo en `data-kind` (sin él,
    disponible), que pinta su relleno (AvailabilityKind::fillClasses()) y su icono; la primera de un rango dice el tipo y
    la segunda sus horas en Mono; las que siguen a otra del mismo tipo (`data-continues`) no llevan filete arriba, así
    el rango se ve como un bloque. `data-changed` pone el punto de «sin guardar».

    Las piezas se colocan con `--col` y `--row`. Cada día es un `role="group"` con `display: contents`, para que sus
    celdas se lean juntas. Si la caja mide menos de 640 px (consulta de contenedor), las celdas solo llevan el icono,
    los días su nombre corto y la columna de horas se angosta.

    En el editor las celdas, los días y las horas son botones que maneja el componente Alpine `availabilityEditor`
    (resources/js/app.js): pintar con clic o arrastre (`touch-none`: en pantalla táctil, el dedo pinta en las celdas y
    la página se desplaza desde la columna de horas o fuera de la cuadrícula), el día o la hora pintan su columna o su
    renglón, y las flechas mueven el foco (un solo botón de la cuadrícula en el orden de tabulación). De solo lectura,
    la cuadrícula se oculta a los lectores de pantalla: los rangos por día dicen lo mismo.
--}}
@props(['grid', 'interactive' => false, 'label' => 'Disponibilidad'])

@php
    use App\Modules\Faculty\Enums\AvailabilityKind;

    $cellClasses = [
        'group/cell relative col-(--col) row-(--row) flex h-9 min-w-0 items-center gap-1.5 overflow-hidden border-t border-l border-base-300 px-2 text-left text-[12.5px] text-base-content',
        AvailabilityKind::fillClasses(),
        'data-continues:border-t-transparent',
        "data-changed:after:absolute data-changed:after:top-1 data-changed:after:right-1 data-changed:after:h-1.5 data-changed:after:w-1.5 data-changed:after:rounded-full data-changed:after:bg-info data-changed:after:content-['']",
        '@max-[40rem]:justify-center @max-[40rem]:px-0',
    ];
    $buttonClasses = 'cursor-pointer touch-none hover:shadow-[inset_0_0_0_2px_color-mix(in_oklab,var(--color-base-content)_30%,transparent)] focus-visible:z-10 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary';
    $headClasses = 'col-(--col) row-start-1 flex min-w-0 flex-col items-start gap-px border-b border-l border-base-300 bg-secondary/40 px-2.5 pt-[7px] pb-1.5 text-left @max-[40rem]:items-center @max-[40rem]:px-0.5 @max-[40rem]:pt-1.5';
    $hourClasses = 'col-start-1 row-(--row) flex h-9 items-center justify-end border-t border-base-300 bg-base-100 px-2 font-mono text-[12.5px] text-neutral tabular-nums @max-[40rem]:px-1.5 @max-[40rem]:text-[11.5px]';
@endphp

<div {{ $attributes->class('@container min-w-0') }}>
    <div
        class="grid grid-cols-[3.25rem_repeat(var(--days),minmax(0,1fr))] overflow-hidden rounded-box border border-base-300 bg-base-100 select-none @max-[40rem]:grid-cols-[2.75rem_repeat(var(--days),minmax(0,1fr))]"
        style="--days: {{ count($grid['days']) }}"
        data-testid="availability-grid"
        @if ($interactive)
            role="application"
            aria-label="{{ $label }}"
            x-ref="grid"
            x-on:pointerdown="down($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="stop()"
            x-on:pointercancel="stop()"
            x-on:lostpointercapture="stop()"
            x-on:click="click($event)"
            x-on:keydown="key($event)"
        @else
            aria-hidden="true"
        @endif
    >
        <div class="col-start-1 row-start-1 border-b border-base-300 bg-secondary/40"></div>

        @foreach ($grid['blocks'] as $h => $block)
            @if ($interactive)
                <button type="button" @class([$hourClasses, 'cursor-pointer hover:bg-base-200 hover:text-base-content', 'border-t-0' => $h === 0]) style="--row: {{ $h + 2 }}"
                    data-hour="{{ $h }}" aria-label="Todos los días de {{ $block['label'] }} a {{ $block['end_label'] }}">{{ $block['label'] }}</button>
            @else
                <div @class([$hourClasses, 'border-t-0' => $h === 0]) style="--row: {{ $h + 2 }}">{{ $block['label'] }}</div>
            @endif
        @endforeach

        @foreach ($grid['days'] as $d => $day)
            <div class="contents" role="group" aria-label="{{ $day['name'] }}: {{ $day['available'] }} h disponibles" data-day-group="{{ $d }}">
                @php
                    $head = '<span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted"><span class="@max-[40rem]:hidden">'.e($day['name']).'</span><span class="hidden @max-[40rem]:inline">'.e($day['short']).'</span></span>'
                        .'<span class="whitespace-nowrap text-[12.5px] text-neutral"><b class="font-mono font-semibold text-base-content"'.($interactive ? ' x-text="hours(dayAvailable['.$d.'])"' : '').'>'.e($day['available']).'</b> h<span class="@max-[40rem]:hidden"> disp.</span></span>';
                @endphp
                @if ($interactive)
                    <button type="button" class="{{ $headClasses }} cursor-pointer hover:bg-base-200" style="--col: {{ $d + 2 }}" data-day="{{ $d }}" aria-label="Todo el {{ mb_strtolower($day['name']) }}">{!! $head !!}</button>
                @else
                    <div class="{{ $headClasses }}" style="--col: {{ $d + 2 }}">{!! $head !!}</div>
                @endif

                @foreach ($grid['cells'][$d] as $h => $cell)
                    @php
                        $block = $grid['blocks'][$h];
                        $state = $cell['kind']?->label() ?? 'Disponible';
                        $cellAttributes = new \Illuminate\View\ComponentAttributeBag(array_filter([
                            'class' => implode(' ', [...$cellClasses, $interactive ? $buttonClasses : '', $h === 0 ? 'border-t-0' : '']),
                            'style' => '--col: '.($d + 2).'; --row: '.($h + 2),
                            'data-kind' => $cell['kind']?->value,
                            'data-continues' => $cell['continues'] ? '' : null,
                        ], fn ($value): bool => $value !== null));
                    @endphp
                    @if ($interactive)
                        <button type="button" {{ $cellAttributes }} data-d="{{ $d }}" data-h="{{ $h }}" tabindex="{{ $d === 0 && $h === 0 ? 0 : -1 }}"
                            aria-label="{{ $day['name'] }} de {{ $block['label'] }} a {{ $block['end_label'] }}: {{ $state }}">
                    @else
                        <div {{ $cellAttributes }} title="{{ $day['name'] }} de {{ $block['label'] }} a {{ $block['end_label'] }}: {{ $state }}">
                    @endif
                        <span class="hidden shrink-0 text-secondary-muted group-data-[kind=unavailable]/cell:inline-flex"><x-icon :name="AvailabilityKind::Unavailable->icon()" class="h-[15px] w-[15px]" /></span>
                        <span class="hidden shrink-0 text-success group-data-[kind=preferred]/cell:inline-flex"><x-icon :name="AvailabilityKind::Preferred->icon()" class="h-[15px] w-[15px]" /></span>
                        <span data-text @if ($cell['mono']) data-mono @endif class="truncate data-mono:font-mono data-mono:text-[12px] data-mono:font-semibold @max-[40rem]:hidden">{{ $cell['text'] }}</span>
                    @if ($interactive)
                        </button>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    </div>
</div>
