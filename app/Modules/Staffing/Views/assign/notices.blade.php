{{--
    Los avisos discretos de un docente del ranking, que no lo bajan al bloque final: «traslado justo» (SC-09, poco
    tiempo para cambiar de campus entre dos de sus sesiones seguidas), en el renglón de sus etiquetas.
--}}
@foreach ($candidate->notices() as $notice => $noticeLabel)
    <span class="inline-flex items-center gap-1 whitespace-nowrap text-neutral" title="Entre dos de sus clases seguidas le queda poco tiempo para cambiar de campus." data-testid="notice-{{ $notice }}">
        <x-icon name="o-map-pin" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $noticeLabel }}
    </span>
@endforeach
