{{--
    Los avisos discretos de un docente del ranking, que no lo bajan al bloque final, en el renglón de sus etiquetas:
    «pasa su banda» (HC-08, aviso desde la tarea 1.50: con el grupo pasa su banda de horas frente a grupo; se puede
    asignar, pero falta el oficio de conformidad del docente) y «traslado justo» (SC-09, poco tiempo para cambiar de
    campus entre dos de sus sesiones seguidas).
--}}
@foreach ($candidate->notices() as $notice => $noticeLabel)
    @php($band = $notice === 'sin_horas')
    <span @class(['inline-flex items-center gap-1 whitespace-nowrap', 'text-warning' => $band, 'text-neutral' => ! $band])
        title="{{ $band ? 'Con este grupo pasa su banda de horas frente a grupo: se puede asignar, pero requiere oficio de conformidad del docente (pendiente).' : 'Entre dos de sus clases seguidas le queda poco tiempo para cambiar de campus.' }}" data-testid="notice-{{ $notice }}">
        <x-icon :name="$band ? 'o-clock' : 'o-map-pin'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $noticeLabel }}
    </span>
@endforeach
