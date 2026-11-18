<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\Core\Models\Campus;

/**
 * Una materia en un campus en «Re-análisis de la carga» (opción B «Por materia»): es donde se ajusta, porque abrir, cerrar
 * o ampliar un grupo toca a todas las carreras que comparten la materia. Trae sus renglones por carrera (las filas del
 * diff, ReanalysisRow) ya filtrados y ordenados (faltantes, sobrantes, ajustadas y las de primer semestre; dentro, la
 * diferencia más grande primero), lo que suman, en cuántos grupos de la versión vigente se ofrecen y lo que ya se ajustó
 * desde ella (`touched`, «E: grupo nuevo»).
 *
 * `compact`: todas sus carreras están dentro del umbral o son de primer semestre, ninguna se marca para revisar y no se
 * ha tocado; entonces va en un renglón con una pastilla por carrera. `scale` es el tope de la regla de sus renglones, el
 * mismo para todos, para que se comparen entre sí.
 */
final readonly class ReanalysisBlock
{
    /**
     * @param  list<ReanalysisRow>  $rows
     * @param  list<string>  $touched
     */
    public function __construct(
        public string $key,
        public string $subjectId,
        public string $subjectCode,
        public string $subjectName,
        public ?Campus $campus,
        public array $rows,
        public int $expected,
        public int $seats,
        public int $groups,
        public bool $compact,
        public array $touched,
        public float $scale,
    ) {}

    /**
     * Si todas sus carreras son de primer semestre (RA-09): no se clasifica.
     */
    public function onlyFirstSemester(): bool
    {
        return array_all($this->rows, fn (ReanalysisRow $row): bool => $row->balance === null);
    }
}
