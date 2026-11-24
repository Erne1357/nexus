{{--
    La cuenta de horas de la barra del turno (SEL-05): «Llevas N h · tu base B (±2)», la barra con la banda del margen
    (de la base −2 a +2) y la marca de la base, y debajo qué le falta o cuánto se pasa. Espera $hours, $base y $compact.
--}}
@use('App\Modules\Staffing\Rules\LoadBand')
@php
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
<div role="group" aria-label="Tus horas" @class(['grid gap-1.5 md:border-s md:border-base-300 md:ps-5', 'min-w-[13rem]' => ! $compact, 'min-w-[11rem]' => $compact]) data-testid="selection-meter">
    <p class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
        <span>Llevas <b class="font-mono text-[15px]">{{ $hours }}</b> h</span>
        <span class="text-neutral">· tu base <b class="font-mono text-base-content">{{ $base }}</b> (±2)</span>
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
