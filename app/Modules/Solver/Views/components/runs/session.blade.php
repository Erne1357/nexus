{{--
    Una sesión en la semana de la comparación (DESIGN.md, «Corridas del motor»): la sesión del editor de horarios
    (scheduling::editor.session) en modo comparación, la misma en la cuadrícula y en la lista del celular (`mobile`). La
    franja a la izquierda es el color de su materia (`--chain`); de arriba abajo, «CLAVE · grupo» en Mono, sus horas con el
    icono de su tipo (en un bloque de una hora, el salón en su lugar), su salón (o el docente en la vista por salón, o el
    campus en la vista por docente) y su marca. El estado lleva borde y marca, nunca solo color:
    - nueva: borde `info` y «Nueva»; movida u otro salón: borde `warning` y de dónde viene («↩ Mar 12:00» o el salón);
      en «Como está hoy», a dónde va («→ Jue 10:00»);
    - sin cambio: sin tinte y la franja al 40 %; se queda (no se escribe): igual, con «Se queda» en `error`;
    - fija (otra carga): `base-200` con borde punteado y «Fija · CB»;
    - el lugar anterior (`ghost`): rayas `warning` con borde punteado y «Antes»; en celular, a dónde pasa.
    La del grupo elegido lleva el anillo `primary`; las de los demás grupos se atenúan (sin tinte ni color de borde, el
    texto conserva su contraste). Las de los grupos de la carga son botones que eligen el grupo.
--}}
@props(['item', 'chainClasses', 'mobile' => false])

@php
    $kindIcon = match ($item['kind']) {
        \App\Modules\Scheduling\Enums\SessionKind::Practica => 'o-beaker',
        \App\Modules\Scheduling\Enums\SessionKind::Virtual => 'o-computer-desktop',
        default => 'o-book-open',
    };
    $short = ! $mobile && ($item['span'] ?? 1) <= 1;
    $where = $item['show_teacher'] ? $item['teacher'] : ($item['room'] ?? 'Sin salón');
    $tinted = 'bg-[color-mix(in_oklab,var(--chain)_9%,var(--color-base-100))] shadow-[inset_4px_0_0_var(--chain)]';
    $quiet = 'bg-base-100 text-neutral shadow-[inset_4px_0_0_color-mix(in_oklab,var(--chain)_40%,var(--color-base-100))]';
    $classes = [
        $item['state'] === 'fixed' || $item['chain'] === null ? '[--chain:var(--color-neutral)]' : $chainClasses[$item['chain'] % count($chainClasses)],
        'flex min-w-0 flex-col overflow-hidden rounded-field text-left text-[11.5px] leading-[1.25]',
        match (true) {
            $item['ghost'] => 'z-[1] border-[1.5px] border-dashed border-warning bg-[image:repeating-linear-gradient(135deg,var(--color-base-100)_0_5px,color-mix(in_oklab,var(--color-warning)_10%,var(--color-base-100))_5px_9px)] text-neutral',
            $item['state'] === 'fixed' => 'border border-dashed border-base-content/30 bg-base-200 text-neutral shadow-[inset_4px_0_0_color-mix(in_oklab,var(--color-neutral)_45%,var(--color-base-200))]',
            $item['muted'] => 'border border-base-300 bg-base-100 text-neutral shadow-[inset_4px_0_0_color-mix(in_oklab,var(--chain)_30%,var(--color-base-100))]',
            in_array($item['state'], ['same', 'kept'], true) => 'border border-base-300 '.$quiet,
            $item['state'] === 'new' => 'border-[1.5px] border-info '.$tinted,
            default => 'border-[1.5px] border-warning '.$tinted,
        },
        $item['selected'] && ! $item['ghost'] ? 'z-[3] outline-2 outline-offset-1 outline-primary' : '',
        $item['selectable'] && ! $item['ghost'] ? 'cursor-pointer hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary' : '',
        $mobile ? 'relative min-h-14 w-full gap-0.5 py-1.5 ps-3 pe-2 text-[12.5px]' : 'absolute top-[calc(var(--top)*3.25rem+1px)] left-[calc(var(--lane)*100%/var(--lanes)+2px)] z-[2] h-[calc(var(--span)*3.25rem-2px)] w-[calc(100%/var(--lanes)-4px)] py-0.5 ps-2 pe-1',
    ];
    $markTone = match ($item['mark']['tone'] ?? null) {
        'new' => 'border border-info/35 bg-info/10 px-1 text-info',
        'mov' => 'border border-warning/35 bg-warning/10 px-1 text-warning',
        'kept' => 'border border-error/35 bg-error/10 px-1 text-error',
        default => 'font-medium text-neutral',
    };
    $attributes = $attributes->merge(array_filter([
        'class' => implode(' ', array_filter($classes)),
        'style' => $mobile ? null : "--top: {$item['top']}; --span: {$item['span']}; --lane: {$item['lane']}; --lanes: {$item['lanes']}",
        'aria-label' => $item['label'],
        'data-session' => $item['id'],
        'data-state' => $item['ghost'] ? 'antes' : $item['state'],
        'wire:key' => ($mobile ? 'm-' : 'w-').$item['id'],
    ], fn ($value): bool => $value !== null));
@endphp

@if ($item['selectable'] && ! $item['ghost'])
    <button type="button" wire:click="selectGroup('{{ $item['group_id'] }}')" aria-pressed="{{ $item['selected'] ? 'true' : 'false' }}" {{ $attributes }}>
@else
    <div {{ $attributes }}>
@endif
    @if ($item['ghost'])
        <span class="text-[11.5px] leading-tight font-bold tracking-[0.09em] text-warning uppercase" aria-hidden="true">Antes</span>
    @endif
    <span @class(['truncate font-mono font-semibold', 'text-base-content' => true, '[word-spacing:-0.3em]' => ! $mobile, 'font-medium' => in_array($item['state'], ['same', 'kept', 'fixed'], true) || $item['ghost']]) aria-hidden="true">{{ $item['title'] }}</span>
    @if ($short && ! $item['ghost'])
        <span class="flex items-center justify-between gap-0.5 font-mono" aria-hidden="true">
            <span class="truncate text-base-content">{{ $where }}</span>
            <x-icon :name="$kindIcon" class="h-3 w-3 shrink-0 text-neutral" />
        </span>
    @else
        <span class="flex items-center justify-between gap-0.5 font-mono text-neutral tabular-nums" aria-hidden="true">
            <span class="truncate">{{ $item['hours'] }}</span>
            @unless ($item['ghost'])
                <x-icon :name="$kindIcon" @class(['shrink-0', 'h-3 w-3' => ! $mobile, 'h-3.5 w-3.5' => $mobile]) />
            @endunless
        </span>
        @unless ($item['ghost'])
            <span class="flex min-w-0 items-center gap-1.5 truncate" aria-hidden="true">
                <span @class(['truncate', 'font-mono' => ! $item['show_teacher']])>{{ $where }}</span>
                @if ($item['show_campus'])
                    <span class="inline-flex h-4 shrink-0 items-center rounded border border-base-content/30 bg-base-100 px-1 font-mono text-[11px] font-semibold text-base-content">{{ $item['campus'] }}</span>
                @endif
            </span>
        @endunless
    @endif
    @if ($item['ghost'] && $mobile && $item['moves_to'])
        <span class="font-mono text-[12px] text-warning" aria-hidden="true">pasa al {{ $item['moves_to'] }}</span>
    @endif
    @if ($item['mark'])
        <span class="flex min-w-0" aria-hidden="true">
            <span class="inline-flex h-[15px] max-w-full items-center gap-[3px] overflow-hidden rounded-[4px] text-[11px] leading-none font-semibold whitespace-nowrap {{ $markTone }}">
                <x-icon :name="$item['mark']['icon']" class="h-3 w-3 shrink-0" />
                <span @class(['truncate', 'font-mono' => in_array($item['mark']['tone'], ['mov'], true)])>{{ $item['mark']['text'] }}</span>
            </span>
        </span>
    @endif
@if ($item['selectable'] && ! $item['ghost'])
    </button>
@else
    </div>
@endif
