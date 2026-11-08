{{--
    Las etiquetas de un docente en el ranking: su plaza (PositionTag, en Mono) y «otro depto.» si viene de otro
    departamento con perfil preferente.
--}}
<span class="badge badge-sm badge-neutral badge-soft whitespace-nowrap font-mono font-semibold" title="Plaza">{{ $positions }}</span>
@if ($fromOther)
    <span class="badge badge-sm badge-info badge-soft gap-1 whitespace-nowrap"><x-icon name="o-arrows-right-left" class="h-3 w-3" aria-hidden="true" />otro depto.</span>
@endif
