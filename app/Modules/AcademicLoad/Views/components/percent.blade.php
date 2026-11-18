{{--
    Un porcentaje de «Precisión de la proyección»: la cifra en Mono con cifras tabulares y la unidad en la letra del texto,
    para que «13.5 %» no se abra con el ancho de Mono. `value` es una fracción (0.135); null pinta «—». `digits`, los
    decimales (1 por omisión); `signed`, con «+» o «−».

    <x-academic-load::percent :value="$metrics->weightedError" />
--}}
@props(['value', 'digits' => 1, 'signed' => false])

@if ($value === null)
    <span {{ $attributes }}>—</span>
@else
    @php
        $number = number_format(abs($value * 100), $digits, '.', ',');
        $sign = $signed && round($value * 100, $digits) != 0 ? ($value > 0 ? '+' : '−') : (! $signed && $value < 0 ? '−' : '');
    @endphp
    <span {{ $attributes->class(['whitespace-nowrap']) }}><span class="font-mono tabular-nums">{{ $sign }}{{ $number }}</span><span class="ms-[0.14em] font-sans">%</span></span>
@endif
