{{--
    La clave de una carrera en «Paralelos» (maqueta B, «kc»): Mono 600 en una caja de 20 px con filete. Con su parte del
    departamento aceptada lleva la palomita y el tinte `success` (sus grupos ya existen y se reorganizan); pendiente, solo
    el filete. El nombre va en `title` y para lectores de pantalla, con el estado de su parte.
--}}
<abbr title="{{ $name }}{{ $accepted ? ' · parte aceptada' : ' · parte pendiente' }}"
    @class([
        'inline-flex h-5 shrink-0 items-center gap-0.5 rounded border px-1.5 font-mono text-[11.5px] font-semibold leading-none tracking-[0.02em] no-underline',
        'border-success/45 bg-success/10 text-success' => $accepted,
        'border-base-content/25 bg-base-100 text-base-content' => ! $accepted,
    ])>@if ($accepted)<x-icon name="o-check" class="h-3 w-3" aria-hidden="true" />@endif{{ $code }}<span class="sr-only"> ({{ $accepted ? 'parte aceptada' : 'parte pendiente' }})</span></abbr>
