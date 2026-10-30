{{--
    La banda de una especialidad en core::semester-grid.frame, desde 768 px: a todo el ancho de la cuadrícula, en su
    renglón (`$row`, de SemesterGrid), con filete punteado arriba, «ESPECIALIDAD», su nombre completo y cuántas
    materias tiene. Sus materias van debajo, cada una en su columna, y así ningún mosaico carga el nombre. Tapa el
    filete de las columnas. Los lectores de pantalla oyen la especialidad dentro de cada semestre, en
    core::semester-grid.separator, en el orden de sus materias.
--}}
@props(['specialty', 'row', 'name', 'subjects'])

<div aria-hidden="true" data-band="{{ $specialty }}" style="--row: {{ $row }}"
    class="flex flex-wrap items-baseline gap-x-2 border-t border-dashed border-neutral/40 bg-base-100 px-3 pt-2.5 pb-1 max-md:hidden md:col-[1/-1] md:row-(--row)">
    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Especialidad</span>
    <span class="font-semibold">{{ $name }}</span>
    <span class="text-neutral"><span class="font-mono">{{ $subjects }}</span> {{ $subjects === 1 ? 'materia' : 'materias' }}</span>
</div>
