{{--
    La regla de un renglón del re-análisis (opción B «Por materia»): los cupos de la versión vigente como barra, la banda
    del umbral de RA-04 alrededor de lo esperado (de esperados × (1 − umbral) a esperados × (1 + umbral): la base es lo
    esperado, como en DemandSettings::balanceOf()) y lo esperado como marca, en el tono de su clase. Si la barra termina
    dentro de la banda, la materia está ajustada; antes, falta oferta; después, sobra. En primer semestre (RA-09) no hay
    banda: la marca es el nuevo ingreso, en gris. Todas las reglas de un bloque usan la misma escala (`scale`), para que se
    comparen. Es decorativa (`aria-hidden`): las cifras van escritas al lado.

    <x-scheduling::reanalysis.rule :row="$row" :scale="$block->scale" :threshold="$board->threshold" />
--}}
@props(['row', 'scale', 'threshold'])

@php
    $at = fn (float $value): float => round(min(100, max(0, $value / $scale * 100)), 2);
    $shown = \App\Modules\Scheduling\Queries\ReanalysisBoard::shownStudents($row);
    $classified = $row->balance !== null;
    $low = $at($shown * (1 - $threshold / 100));
    $high = $at($shown * (1 + $threshold / 100));
@endphp

<span {{ $attributes->class(['relative block h-[22px] rounded bg-base-200']) }} aria-hidden="true" data-rule>
    @if ($classified && $shown > 0)
        <span class="absolute inset-y-0 border-x border-success/50 bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-success)_28%,transparent)_0_3px,transparent_3px_6px)]" style="left: {{ $low }}%; width: {{ max(0.5, $high - $low) }}%" data-band></span>
    @endif
    <span class="absolute start-0 top-[5px] h-3 rounded-sm bg-primary/25" style="width: {{ $at($row->offeredSeats) }}%" data-seats></span>
    <span @class([
        'absolute -top-0.5 -bottom-0.5 -ms-[1.5px] w-[3px] rounded-sm',
        'bg-error' => $row->balance === \App\Modules\Demand\Enums\DemandBalance::Shortfall,
        'bg-warning' => $row->balance === \App\Modules\Demand\Enums\DemandBalance::Surplus,
        'bg-success' => $row->balance === \App\Modules\Demand\Enums\DemandBalance::Adjusted,
        'bg-neutral' => ! $classified,
    ]) style="left: {{ $at($shown) }}%" data-expected></span>
</span>
