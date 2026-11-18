{{--
    Una carrera en el desglose «Por carrera» del drawer de «Demanda por materia»: su clave, el estado de su propuesta y, en la
    lente de carrera, «(tu carrera)»; debajo, el método de la proyección y «Revisar» en una materia de especialidad. La usan
    la tabla y los renglones apilados del celular.
--}}
<span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
    <span class="font-mono font-bold" title="{{ $line->career->name }}">{{ $line->career->code }}</span>
    @include('academic-load::demand.state', ['state' => $line->state])
    @if ($mine)
        <span class="text-xs text-neutral">(tu carrera)</span>
    @endif
</span>
<span class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-neutral">
    <span>{{ $line->method?->label() ?? 'Sin proyección' }}</span>
    @if ($line->needsReview)
        <span class="inline-flex items-center gap-1 font-medium text-warning" title="Materia de especialidad: depende de qué especialidad elige cada alumno."><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Revisar</span>
    @endif
</span>
