{{--
    La cuenta de horas de la barra del turno (SEL-05): «Llevas N h · frente a grupo: B h (B−2 a B+2)», la barra con la banda del margen
    y la marca de las horas frente a grupo (PL-01), y debajo qué le falta o cuánto se pasa. Espera $hours, $base y $compact.
    Con plaza administrativa (PL-03; $administrative con sus horas y $cap con el tope) no hay banda: «Llevas N h · plaza
    administrativa: A h» y debajo «N+A de 42 h»; en el tope, «ya no te caben clases».
--}}
@use('App\Modules\Staffing\Rules\LoadBand')
@php
    $administrative ??= null;
    $low = $base - LoadBand::MARGIN;
    $high = $base + LoadBand::MARGIN;
    $max = max(30, $hours + 2, $high + 2);
    $at = fn (int $value): float => round(min(max($value, 0), $max) / $max * 100, 2);
    $band = LoadBand::classify($hours, $base);
    $text = match ($band) {
        'under' => 'Te faltan de '.($low - $hours).' a '.($high - $hours).' h',
        'over' => 'Pasas tu margen por '.($hours - $high).' h',
        default => 'Dentro de tu margen',
    };
@endphp
@if ($administrative !== null)
    @php($withPlaza = $hours + $administrative)
    <div role="group" aria-label="Tus horas con plaza administrativa" @class(['grid gap-1', 'min-w-[13rem]' => ! $compact, 'min-w-[11rem]' => $compact, 'md:border-s md:border-base-300 md:ps-5']) data-testid="selection-meter">
        <p class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
            <span>Llevas <b class="font-mono text-[15px]">{{ $hours }}</b> h</span>
            <span class="text-neutral">· plaza administrativa: <b class="font-mono text-base-content">{{ $administrative }}</b> h</span>
        </p>
        <p class="flex items-center gap-1 text-[12.5px] font-semibold text-success" data-testid="selection-meter-administrative">
            <x-icon name="o-check-circle" class="h-3.5 w-3.5" aria-hidden="true" /><span><span class="font-mono">{{ $withPlaza }}</span> de <span class="font-mono">{{ $cap }}</span> h{{ $withPlaza >= $cap ? ' · ya no te caben clases' : '' }}</span>
        </p>
    </div>
@else
<div role="group" aria-label="Tus horas frente a grupo" @class(['grid gap-1.5 md:border-s md:border-base-300 md:ps-5', 'min-w-[13rem]' => ! $compact, 'min-w-[11rem]' => $compact]) data-testid="selection-meter">
    <p class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
        <span>Llevas <b class="font-mono text-[15px]">{{ $hours }}</b> h</span>
        <span class="text-neutral">· frente a grupo: <b class="font-mono text-base-content">{{ $base }}</b> h ({{ $low }} a {{ $high }})</span>
    </p>
    <div class="relative my-1 h-2 w-full max-w-64 rounded-full border border-base-300 bg-base-200" aria-hidden="true">
        <span class="absolute -inset-y-1 rounded-[4px] border border-success/55 bg-success/15" style="left: {{ $at($low) }}%; width: {{ $at($high) - $at($low) }}%"></span>
        <span class="absolute inset-y-0 start-0 rounded-full bg-primary" style="width: {{ $at($hours) }}%"></span>
        <span class="absolute -inset-y-1.5 w-0.5 -translate-x-1/2 bg-success" style="left: {{ $at($base) }}%"></span>
    </div>
    <p @class(['flex items-center gap-1 text-[12.5px] font-semibold', 'text-success' => $band === 'within', 'text-warning' => $band !== 'within'])>
        <x-icon :name="$band === 'within' ? 'o-check-circle' : 'o-exclamation-triangle'" class="h-3.5 w-3.5" aria-hidden="true" />{{ $text }}
    </p>
</div>
@endif
