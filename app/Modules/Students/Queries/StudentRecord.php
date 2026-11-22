<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Queries\SubjectAliasQuery;
use App\Modules\Core\Support\SemesterGrid;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/**
 * La ficha de un alumno (tarea 1.6), como la arma StudentRecordQuery: sus cifras, su retícula por semestre,
 * las especialidades y las cadenas de seriación de esa retícula, su kárdex por periodo y, aparte, las materias de su
 * plan que esperan semestre.
 */
final readonly class StudentRecord
{
    /**
     * @param  int  $totalCredits  créditos del plan, o de su retícula con una sola especialidad si no están capturados
     * @param  int  $subjectsInGrid  materias de su retícula que ve, más las que aprobó y esperan semestre en «Por validar»
     * @param  int  $selectable  materias que ya puede seleccionar: las que puede cursar y no está cursando
     * @param  int|null  $reachSemester  hasta qué semestre puede seleccionar; sin semestre, sin límite
     * @param  array<int, int>  $approvedBySemester  semestre → cuántas de sus materias acreditó
     * @param  array<int, list<GridCell>>  $semesters  semestre → sus materias: primero las del tronco común y luego las de cada especialidad, en el orden de `$specialties`; cada grupo por clave
     * @param  list<array{id: string, name: string, subjects: int}>  $specialties  las especialidades con materias en la retícula, por su clave, y cuántas tiene cada una
     * @param  list<array{index: int, name: string, size: int}>  $chains  cadenas de seriación, por su primera materia
     * @param  list<array{term: string|null, label: string, records: list<KardexRecord>}>  $kardex  primero lo que no tiene periodo y luego del periodo más antiguo al más reciente
     * @param  list<array{id: string, code: string, name: string, credits: int, accredited: bool}>  $unplaced  las materias de su plan que esperan semestre en «Por validar» (D18), por su renglón del SII y luego por clave, y si las acreditó
     * @param  array<string, string>  $siiCodes  materia → su clave del SII en el plan del alumno, solo si difiere de la de Nexus (D8-bis)
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
        public array $approvedBySemester,
        public array $semesters,
        public array $specialties,
        public array $chains,
        public array $kardex,
        public array $unplaced = [],
        public array $siiCodes = [],
    ) {}

    /**
     * La retícula acomodada en la cuadrícula de Core: columnas por semestre, el tronco común arriba y una banda por especialidad.
     */
    public function gridLayout(): SemesterGrid
    {
        return SemesterGrid::place(
            array_map(fn (array $cells): array => collect($cells)->mapWithKeys(fn (GridCell $cell): array => [$cell->code => $cell->specialtyId])->all(), $this->semesters),
            array_column($this->specialties, 'id'),
        );
    }

    /**
     * @return array<string, string> id de especialidad → su nombre
     */
    /**
     * La clave principal y la secundaria con que se pinta una materia en la retícula y el kárdex del alumno (D8-bis).
     *
     * @return array{0: string, 1: ?string}
     */
    public function codesFor(string $subjectId, string $code): array
    {
        return SubjectAliasQuery::display($code, $this->siiCodes[$subjectId] ?? null);
    }

    public function specialtyNames(): array
    {
        return array_column($this->specialties, 'name', 'id');
    }
}
