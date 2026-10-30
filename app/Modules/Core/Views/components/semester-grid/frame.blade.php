{{--
    El marco de una retícula por semestre (DESIGN.md, "Retícula del alumno" y "Retícula del plan (editor)"), el mismo
    en la ficha del alumno y en el editor del plan. Desde 768 px es una sola cuadrícula: una columna por semestre,
    de 6.875 a 9 rem (así un plan de nueve semestres cabe desde 1366 px, aun con la barra de desplazamiento de Windows), y los renglones que calcula `$layout`
    (App\Modules\Core\Support\SemesterGrid), más uno corto al pie. La caja blanca se ajusta a la cuadrícula y, si no
    cabe, se desplaza de lado ella y no la página. Pinta el fondo de cada columna; `$current` es el semestre cuya
    columna va resaltada. Los semestres (core::semester-grid.semester), las bandas y los mosaicos van en el slot.
    Debajo de 768 px los semestres se apilan, cada uno en su caja. Los atributos caen en la cuadrícula, que es el
    grupo `grid` con el que se atenúa lo que no está resaltado (`data-focus`).
--}}
@props(['layout', 'current' => null])

<div class="max-w-full overflow-x-auto rounded-box border border-base-300 bg-base-100 md:w-fit max-md:overflow-visible max-md:border-0 max-md:bg-transparent">
    <div {{ $attributes->class(['group/grid relative max-md:flex max-md:flex-col max-md:gap-3 md:grid md:grid-cols-[repeat(var(--columns),minmax(6.875rem,9rem))] md:grid-rows-[repeat(var(--rows),auto)_0.25rem] md:gap-y-2']) }}
        style="--columns: {{ max(1, count($layout->columns)) }}; --rows: {{ $layout->rowCount }}">
        @foreach ($layout->columns as $semester => $column)
            <x-core::semester-grid.column :semester="$semester" :column="$column" :current="$semester === $current" />
        @endforeach
        {{ $slot }}
    </div>
</div>
