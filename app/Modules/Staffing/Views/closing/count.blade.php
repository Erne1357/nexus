{{--
    Una cifra de «Avance del cierre» como insignia con su icono (el color nunca va solo); cero es un guion tenue. Los
    iconos son los de «Para cerrar». En la matriz el encabezado de la columna dice qué cuenta; en los renglones apilados
    no hay encabezado, así que con `$labelled` la insignia lo dice («122 sin docente»). `$kind`: hard, unscheduled,
    teacher, room u others; `$n`.
--}}
@php($meta = [
    'hard' => ['o-x-circle', 'error', 'con choque duro', 'con choque duro'],
    'unscheduled' => ['o-clock', 'warning', 'sin horario', 'sin horario'],
    'teacher' => ['o-user', 'warning', 'sin docente', 'sin docente'],
    'room' => ['o-map-pin', 'warning', 'con sesiones sin salón', 'sin salón'],
    'others' => ['o-ellipsis-horizontal', 'warning', 'con docente de baja, solicitudes o acuerdos', 'otros'],
][$kind])
@if ($n === 0)
    <span class="font-mono text-neutral/60" aria-hidden="true">–</span><span class="sr-only">Ninguno {{ $meta[2] }}</span>
@elseif ($labelled ?? false)
    <x-core::status-badge :tone="$meta[1]" :icon="$meta[0]" :label="(string) $n" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span class="font-sans" aria-hidden="true">{{ $meta[3] }}</span><span class="sr-only"> {{ $meta[2] }}</span></x-core::status-badge>
@else
    <x-core::status-badge :tone="$meta[1]" :icon="$meta[0]" :label="(string) $n" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5"><span class="sr-only"> {{ $meta[2] }}</span></x-core::status-badge>
@endif
