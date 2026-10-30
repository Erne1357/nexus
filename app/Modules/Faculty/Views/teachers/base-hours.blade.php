{{--
    Horas base de un docente en el periodo activo (TeacherQuery::baseHoursFor), en Mono; sin periodo activo, una raya.
--}}
@if ($hours === null)
    <span class="font-mono text-neutral" title="Sin periodo activo">—</span>
@else
    <span class="font-mono tabular-nums">{{ $hours }}</span>
@endif
