{{--
    El fondo de la columna de un semestre en core::semester-grid.frame, de arriba abajo: el filete que la separa de
    la anterior y, si es la resaltada (el semestre del alumno), el fondo `bg-base-200`. Las bandas de especialidad lo
    tapan. Solo es dibujo: los lectores de pantalla no lo ven, y en celular no se pinta.
--}}
@props(['semester', 'column', 'current' => false])

<div aria-hidden="true" data-column="{{ $semester }}" style="--col: {{ $column }}"
    @class(['max-md:hidden md:col-(--col) md:row-[1/-1]', 'border-l border-base-300' => $column > 1, 'bg-base-200' => $current])></div>
