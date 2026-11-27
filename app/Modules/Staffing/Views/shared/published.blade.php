{{--
    La línea discreta de lo publicado en «Mi carga» y en la oferta (P8): «Publicado el 8 dic. 2026 · v2», una por carga
    publicada de las que se ven; con varias cargas a la vista, cada una dice de cuál («C2: publicado el …»). Solo los
    textos, sin contenedor: quien la incluye pone la caja. Espera $stamps de PublishedStamps::of().
--}}
@foreach ($stamps as $stamp)
    <span>{{ $stamp['scope'] === null ? 'Publicado' : $stamp['scope'].': publicado' }} el {{ $stamp['published_at']->timezone(config('app.timezone'))->translatedFormat('j M Y') }} · v{{ $stamp['version'] }}</span>
@endforeach
