<?php

namespace App\Modules\Students\Queries;

use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/**
 * La ficha de un alumno (tarea 1.6), como la arma StudentRecordQuery: sus cifras, su retícula por semestre,
 * las cadenas de seriación de esa retícula y su kárdex por periodo.
 */
final readonly class StudentRecord
{
    /**
     * @param  int  $totalCredits  créditos del plan, o de su retícula con una sola especialidad si no están capturados
     * @param  int  $selectable  materias que ya puede seleccionar: las que puede cursar y no está cursando
     * @param  int|null  $reachSemester  hasta qué semestre puede seleccionar; sin semestre, sin límite
     * @param  array<int, list<GridCell>>  $semesters  semestre → sus materias, en orden de clave
     * @param  list<array{index: int, name: string, size: int}>  $chains  cadenas de seriación, por su primera materia
     * @param  list<array{term: string, records: list<KardexRecord>}>  $kardex  del periodo más reciente al más antiguo
     */
    public function __construct(
        public Student $student,
        public int $creditsApproved,
        public int $totalCredits,
        public int $subjectsApproved,
        public int $subjectsInGrid,
        public int $taking,
        public int $selectable,
        public ?int $reachSemester,
        public array $semesters,
        public array $chains,
        public array $kardex,
    ) {}
}
