{{--
    Los campus donde se abrió un periodo, como insignias pequeñas con el código en Mono (core::pages.terms). `$term`
    trae `openings.campus` cargado; sin aperturas no pinta nada.
--}}
<span class="inline-flex flex-wrap gap-1" data-testid="opened-in">
    @foreach ($term->opened_campus_codes as $code)
        <span class="rounded border border-base-300 bg-base-100 px-1.5 py-0.5 font-mono text-xs font-semibold">{{ $code }}</span>
    @endforeach
</span>
