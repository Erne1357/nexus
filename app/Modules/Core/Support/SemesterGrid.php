<?php

namespace App\Modules\Core\Support;

/**
 * El acomodo de una retícula por semestre en una sola cuadrícula (DESIGN.md, "Retícula del alumno" y "Retícula
 * del plan (editor)"), el mismo para la ficha del alumno y el editor del plan, que lo pintan con las piezas de
 * `core::components.semester-grid`. Una columna por semestre; el renglón 1 es de los encabezados; luego el
 * tronco común, cada materia en su columna y una debajo de otra; y después, por especialidad, una banda a todo
 * el ancho y sus materias debajo, cada una en su columna. Cada bloque mide lo que su semestre más largo, así
 * los mosaicos de un mismo renglón se alinean entre columnas. Solo calcula: no lee la base ni guarda estado.
 */
final readonly class SemesterGrid
{
    /**
     * @param  array<int, int>  $columns  semestre → su columna (1, 2, …), en orden de semestre
     * @param  array<array-key, int>  $rows  materia → su renglón, en el orden en que llegaron
     * @param  array<array-key, int>  $bands  especialidad → el renglón de su banda, en el orden de las bandas
     * @param  int  $rowCount  cuántos renglones tiene, contando el de los encabezados
     */
    private function __construct(
        public array $columns,
        public array $rows,
        public array $bands,
        public int $rowCount,
    ) {}

    /**
     * Acomoda `$semesters`: cada semestre con sus materias en el orden en que se ven, y la sección de cada una
     * (null para el tronco común, o la llave de su especialidad). Las especialidades van en el orden de
     * `$sections`, y una que no esté ahí, al final, en el orden en que aparece; una sin materias no abre banda.
     *
     * @param  array<int, array<array-key, array-key|null>>  $semesters  semestre → [materia => su sección]
     * @param  list<array-key>  $sections  las especialidades en el orden de sus bandas
     */
    public static function place(array $semesters, array $sections = []): self
    {
        ksort($semesters);

        $heights = [];

        foreach ($semesters as $subjects) {
            foreach (array_count_values(array_map(fn (int|string|null $section): string => self::key($section), $subjects)) as $section => $count) {
                $heights[$section] = max($heights[$section] ?? 0, $count);
            }
        }

        $common = $heights[self::key(null)] ?? 0;
        unset($heights[self::key(null)]);

        $next = 2 + $common;
        $start = [self::key(null) => 2];
        $bands = [];

        foreach (array_unique([...array_map(self::key(...), $sections), ...array_keys($heights)]) as $section) {
            if (! isset($heights[$section])) {
                continue;
            }

            $bands[substr($section, 1)] = $next;
            $start[$section] = $next + 1;
            $next += 1 + $heights[$section];
        }

        $rows = [];

        foreach ($semesters as $subjects) {
            $taken = [];

            foreach ($subjects as $subject => $section) {
                $section = self::key($section);
                $rows[$subject] = $start[$section] + ($taken[$section] ?? 0);
                $taken[$section] = ($taken[$section] ?? 0) + 1;
            }
        }

        return new self(
            columns: $semesters === [] ? [] : array_combine(array_keys($semesters), range(1, count($semesters))),
            rows: $rows,
            bands: $bands,
            rowCount: $next - 1,
        );
    }

    /**
     * La sección como llave de arreglo: el tronco común no choca con ninguna especialidad, ni una especialidad
     * de llave numérica con otra de texto.
     */
    private static function key(int|string|null $section): string
    {
        return $section === null ? '' : "#{$section}";
    }
}
