{{--
    Cuántos grupos tienen una cosa pendiente, como insignia con su icono (el color nunca va solo); cero es un guion
    tenue. `$kind`: hard, unscheduled, teacher o room.
--}}
@php($meta = [
    'hard' => ['o-x-circle', 'error', 'con choque duro'],
    'unscheduled' => ['o-clock', 'warning', 'sin horario'],
    'teacher' => ['o-user', 'warning', 'sin docente'],
    'room' => ['o-building-office', 'warning', 'sin salón'],
][$kind])
@if ($n === 0)
    <span class="font-mono text-neutral/60" aria-label="Ninguno {{ $meta[2] }}">—</span>
@else
    <x-core::status-badge :tone="$meta[1]" :icon="$meta[0]" :label="(string) $n" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span class="sr-only"> {{ $n === 1 ? 'grupo' : 'grupos' }} {{ $meta[2] }}</span></x-core::status-badge>
@endif
