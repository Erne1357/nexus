{{--
    La barra de selección de una retícula (DESIGN.md, "Retícula del alumno" y "Retícula del plan (editor)"): lo que
    dice lo resaltado (`$info`: qué pide y qué abre una materia, o cuántas materias tiene una cadena) y «Quitar
    resaltado» (`$clear` trae su acción). Va pegada al pie de la ventana mientras la retícula está a la vista, y solo
    se ve con algo resaltado (`$shown`, o `data-shown` si la pinta Alpine). Su texto es `aria-live` y está siempre en
    la página, vacío cuando no hay nada, para que el lector de pantalla diga cada cambio.
--}}
@props(['shown' => false])

<div {{ $attributes->class([
    'group/bar sticky bottom-3 z-10 flex items-center gap-3 rounded-box',
    'data-[shown]:border data-[shown]:border-base-300 data-[shown]:bg-base-100 data-[shown]:py-2 data-[shown]:pr-2 data-[shown]:pl-4 data-[shown]:shadow-float',
]) }} @if ($shown) data-shown @endif data-testid="selection-bar">
    <p {{ $info->attributes->class('min-w-0 flex-1 leading-snug') }} aria-live="polite" data-testid="chain-info">{{ $info }}</p>
    <button type="button" {{ $clear->attributes->class('btn btn-ghost btn-sm hidden shrink-0 group-data-[shown]/bar:inline-flex') }}>Quitar resaltado</button>
</div>
