{{--
    Una sesión en la semana del editor de horarios (DESIGN.md, "Editor de horarios"), la misma en la cuadrícula y en la
    lista del celular (`mobile`). La franja a la izquierda es el color de su materia (`--chain`, los tokens de las
    cadenas de la retícula); de arriba abajo: «CLAVE · grupo» en Mono, sus horas en Mono con el icono de su tipo, y su
    salón en Mono o «Sin salón» con icono de advertencia (en la vista por docente, el campus si es otro; por salón, el
    docente). Una sesión con choque duro lleva borde `error` y una insignia por regla: el color nunca va solo. La del
    grupo elegido lleva el anillo `primary`; las demás se atenúan mientras se coloca. La que no es del semestre va en
    gris con borde punteado y no se abre.

    En la cuadrícula se coloca con `--top`, `--span`, `--lane` y `--lanes` (renglones de 3.25rem) y lleva lo que leen
    las flechas de `scheduleEditor`: `data-nav`, su lugar (`data-x`, `data-r0`, `data-r1`) y `tabindex` (una sola
    parada para toda la cuadrícula, `$tabStop`). La que queda bajo las celdas del grupo elegido (`covered`) no entra a
    las flechas. `data-found` la marca un momento tras «Ver».
--}}
@props(['item', 'chainClasses', 'dayIndex' => 0, 'tabStop' => null, 'mobile' => false])

@php
    $kindIcon = match ($item['kind']) {
        \App\Modules\Scheduling\Enums\SessionKind::Practica => 'o-beaker',
        \App\Modules\Scheduling\Enums\SessionKind::Virtual => 'o-computer-desktop',
        default => 'o-book-open',
    };
    $where = $item['room'] ?? ($item['roomless'] ? 'sin salón' : $item['note']);
    $label = $item['title'].', '.\App\Modules\Scheduling\Validation\Minutes::dayName($item['day']).' '.$item['hours']
        .($where ? ', '.$where : '').', '.mb_strtolower($item['kind']->label())
        .($item['rules'] !== [] ? ', choque '.implode(' ', $item['rules']) : '');
    $classes = [
        $item['external'] || $item['chain'] === null ? '[--chain:var(--color-neutral)]' : $chainClasses[$item['chain'] % count($chainClasses)],
        'flex min-w-0 flex-col overflow-hidden rounded-field border text-left text-[11.5px] leading-[1.25] shadow-[inset_4px_0_0_var(--chain)]',
        'data-found:outline-3 data-found:outline-offset-2 data-found:outline-primary',
        $item['external'] ? 'border-dashed border-base-300 bg-base-200' : 'bg-[color-mix(in_oklab,var(--chain)_9%,var(--color-base-100))]',
        $item['rules'] !== [] ? 'border-2 border-error' : ($item['external'] ? '' : 'border-base-300'),
        $item['mine'] ? 'outline-2 outline-offset-1 outline-primary' : '',
        $item['dim'] ? 'opacity-50' : '',
        $item['open'] ? 'cursor-pointer hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary' : '',
        $mobile ? 'relative min-h-14 w-full gap-0.5 py-1.5 ps-3 pe-2' : 'absolute top-[calc(var(--top)*3.25rem+1px)] left-[calc(var(--lane)*100%/var(--lanes)+2px)] z-[2] h-[calc(var(--span)*3.25rem-2px)] w-[calc(100%/var(--lanes)-4px)] py-0.5 ps-2.5 pe-1.5',
    ];
    $attributes = $attributes->merge(array_filter([
        'class' => implode(' ', array_filter($classes)),
        'style' => $mobile ? null : "--top: {$item['top']}; --span: {$item['span']}; --lane: {$item['lane']}; --lanes: {$item['lanes']}",
        'data-session' => $item['id'],
        'data-nav' => $mobile || ! $item['open'] || $item['covered'] ? null : '',
        'data-x' => $mobile ? null : $dayIndex + ($item['lane'] + 0.5) / $item['lanes'],
        'data-r0' => $mobile ? null : $item['top'],
        'data-r1' => $mobile ? null : $item['top'] + $item['span'],
        'tabindex' => $mobile || ! $item['open'] ? null : ($tabStop === 'session:'.$item['id'] && ! $item['covered'] ? '0' : '-1'),
        'aria-label' => $label,
        'data-day' => $item['day'],
        'wire:key' => ($mobile ? 'm-' : 'w-').$item['id'],
    ], fn ($value): bool => $value !== null));
@endphp

@if ($item['open'])
    <button type="button" wire:click="$dispatchTo('scheduling::components.session-drawer', 'open-session', { id: '{{ $item['id'] }}' })" data-opener {{ $attributes }}>
@else
    <div {{ $attributes }}>
@endif
    <span class="truncate font-mono font-semibold">{{ $item['title'] }}</span>
    <span class="flex items-center justify-between gap-1 font-mono text-neutral tabular-nums">
        <span class="truncate">{{ $item['hours'] }}</span>
        <span title="{{ $item['kind']->label() }}"><x-icon :name="$kindIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /></span>
    </span>
    <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
        @if ($item['room'])
            <span class="font-mono">{{ $item['room'] }}</span>
        @elseif ($item['roomless'])
            <span class="inline-flex items-center gap-1 font-medium text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Sin salón</span>
        @endif
        @if ($item['note'])
            <span class="inline-flex min-w-0 items-center gap-1 text-neutral"><x-icon :name="$item['noteIcon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ $item['note'] }}</span></span>
        @endif
        @foreach ($item['rules'] as $rule)
            <span class="inline-flex h-4 items-center gap-0.5 rounded-selector border border-error/40 bg-error/10 px-1 font-mono font-semibold text-error"><x-icon name="o-x-circle" class="h-3 w-3 shrink-0" aria-hidden="true" />{{ $rule }}</span>
        @endforeach
    </span>
@if ($item['open'])
    </button>
@else
    </div>
@endif
