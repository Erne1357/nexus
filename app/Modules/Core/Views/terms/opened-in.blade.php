{{--
    Los campus donde se abrió un periodo, como insignias pequeñas con el código en Mono (core::pages.terms). `$term`
    trae `openings.campus` cargado; sin aperturas no pinta nada.
--}}
<span class="inline-flex flex-wrap gap-1" data-testid="opened-in">
    @php($codes = $term->opened_campus_codes)
    @foreach (array_slice($codes, 0, 3) as $code)
        <span class="rounded border border-base-300 bg-base-100 px-1.5 py-0.5 font-mono text-xs font-semibold">{{ $code }}</span>
    @endforeach
    @if (count($codes) > 3)
        <span class="rounded border border-base-300 bg-base-100 px-1.5 py-0.5 text-xs font-semibold" title="{{ implode(', ', $codes) }}">+{{ count($codes) - 3 }}</span>
    @endif
</span>
