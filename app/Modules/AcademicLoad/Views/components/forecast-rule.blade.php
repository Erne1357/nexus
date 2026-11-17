{{--
    La regla del error de «Precisión de la proyección» (variante B «Contra la meta»): de 0 a 60 %, con la zona y la línea
    de la meta del 15 % (DM-22, ForecastAccuracy::STRONG_THRESHOLD), el aro de la regla de R1 (la base) y el punto de
    Nexus, unidos por una línea que dice cuánto mejora. Lo que pasa del 60 % se queda en la orilla con una flecha. El
    punto va en `success` dentro de la meta y en `warning` fuera; el veredicto lo dicen además el grupo y la tabla, porque
    el color nunca va solo. `big`, la de todo el Tec, con sus rótulos «Nexus 13.5 %» y «R1 37.9 %» arriba. Es
    decorativa: su contenido va en el nombre accesible del renglón y en la vista «Tabla».

    <x-academic-load::forecast-rule :metrics="$metrics" />
--}}
@props(['metrics', 'big' => false])

@php
    $scale = 0.6;
    $meta = \App\Modules\AcademicLoad\Rules\ForecastAccuracy::STRONG_THRESHOLD;
    $at = fn (float $value): float => round(min($value, $scale) / $scale * 100, 2);
    $error = $metrics->weightedError;
    $baseline = $metrics->baselineError;
    $edge = fn (float $x): string => $x < 8 ? 'translate-x-0' : ($x > 92 ? '-translate-x-full' : '-translate-x-1/2');
@endphp

<span {{ $attributes->class(['relative block h-full min-w-0', 'min-h-10' => $big, 'min-h-11' => ! $big]) }} aria-hidden="true" data-rule>
    <span class="absolute inset-y-0 left-0 bg-success/10" style="width: {{ $at($meta) }}%"></span>
    <span class="absolute inset-x-0 top-1/2 h-px bg-base-300"></span>
    <span class="absolute inset-y-0 -ms-px w-0.5 bg-success/80" style="left: {{ $at($meta) }}%"></span>
    @if ($error !== null && $baseline !== null)
        @php
            [$low, $high] = [min($at($error), $at($baseline)), max($at($error), $at($baseline))];
            $together = abs($at($error) - $at($baseline)) < 22;
        @endphp
        <span @class(['absolute top-1/2 -translate-y-1/2 bg-base-content/25', 'h-[3px]' => $big, 'h-0.5' => ! $big]) style="left: {{ $low }}%; width: {{ $high - $low }}%"></span>
        <span @class(['absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-neutral bg-base-100', 'h-4 w-4' => $big, 'h-3 w-3' => ! $big]) style="left: {{ $at($baseline) }}%"></span>
        @if ($baseline > $scale)
            <x-icon name="o-chevron-right" class="absolute -right-3.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral" />
        @endif
        <span @class([
            'absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-base-100',
            'h-5 w-5' => $big,
            'h-[15px] w-[15px]' => ! $big,
            'bg-success' => $error <= $meta,
            'bg-warning' => $error > $meta,
        ]) style="left: {{ $at($error) }}%"></span>
        @if ($big)
            <span class="absolute bottom-[calc(100%+2px)] {{ $edge($at($error)) }} whitespace-nowrap text-[13px] font-semibold" style="left: {{ $at($error) }}%">Nexus <x-academic-load::percent :value="$error" />@if ($together)<span class="font-normal text-neutral"> · R1 <x-academic-load::percent :value="$baseline" /></span>@endif</span>
            @unless ($together)
                <span class="absolute bottom-[calc(100%+2px)] {{ $edge($at($baseline)) }} whitespace-nowrap text-[13px] text-neutral" style="left: {{ $at($baseline) }}%">R1 <x-academic-load::percent :value="$baseline" /></span>
            @endunless
        @endif
    @endif
</span>
