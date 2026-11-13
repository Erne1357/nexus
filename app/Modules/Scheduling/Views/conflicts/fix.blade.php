{{--
    El enlace a la pantalla que corrige un pendiente de un grupo: el editor de horarios (`editor`) o la asignación
    docente (`assign`), en el campus del grupo (`campus`) y, el editor, en la modalidad del tablero (`?modalidad=`). Si esa pantalla todavía no existe, queda deshabilitado con su
    motivo (DESIGN.md, «Nada oculto»).
--}}
@php($route = $to === 'assign' ? 'staffing.assign' : 'scheduling.editor')
@php($reason = $to === 'assign' ? 'La asignación docente llega con la tarea 3.9.' : 'El editor de horarios llega con la tarea 3.5.')
@if ($links[$to])
    <a href="{{ route($route, [...($to === 'editor' && isset($shownModality) ? ['modalidad' => $shownModality->code] : []), 'campus' => $campus, 'grupo' => $group]) }}" wire:navigate class="btn btn-ghost btn-sm -ms-2 gap-1.5">{{ $label }}<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
@else
    <span class="btn btn-ghost btn-sm btn-disabled -ms-2 gap-1.5" aria-disabled="true" title="{{ $reason }}">{{ $label }}<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /><span class="sr-only">: {{ $reason }}</span></span>
@endif
