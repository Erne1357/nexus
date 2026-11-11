<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que encontró la verificación de una corrida (VerifySiiMigration): cuentas y lo que no cuadra. Los números de
 * control solo van en `$incompleteInNexus` y `$missingFromNexus`, que terminan en `_incidencias.csv` (en `storage/`),
 * nunca en la consola.
 */
final readonly class SiiVerification
{
    /**
     * @param  int  $historyRows  renglones de `historia_alumno` comparados
     * @param  int  $students  alumnos de `alumnos_todos` comparados
     * @param  array<string, int>  $diagnoses  renglones por diagnóstico, en el orden de VerificationDiagnosis, sin los que no tienen ninguno
     * @param  list<array{status: string, students: int, completeSii: int, completeNexus: int, missing: int, excluded: int}>  $statuses  por estatus del SII
     * @param  list<array{control: string, grid: string, required: string, sii: string, plan: string, planTotal: int|null, nexus: int|null, lost: string}>  $incompleteInNexus
     * @param  list<array{control: string, grid: string, required: string, sii: string}>  $missingFromNexus  completos en el SII, de una retícula que se migra, que no están en Nexus
     * @param  int  $lostCredits  alumnos incompletos en Nexus que cuentan ahí menos créditos que en el SII
     * @param  list<array{materia: string, rows: int, lines: string}>  $unexplained  por clave del SII; `lines`, las primeras líneas de la historia
     * @param  list<array{materia: string, subjects: string, rows: int}>  $outsidePlanApproved  aprobadas de la retícula del SII que en Nexus no cuentan, por clave
     * @param  list<array{materia: string, subjects: string, rows: int}>  $outsideSiiGrid  por clave del SII, con las materias de Nexus a las que llegó
     * @param  list<array{materia: string, subject: string, sii: string|null, nexus: int, rows: int}>  $creditsDiffer  por clave del SII y materia de Nexus
     * @param  float  $progressSeconds  lo que tardó recalcular el avance de los alumnos
     */
    public function __construct(
        public int $historyRows,
        public int $students,
        public array $diagnoses,
        public array $statuses,
        public array $incompleteInNexus,
        public array $missingFromNexus,
        public int $lostCredits,
        public array $unexplained,
        public array $outsidePlanApproved,
        public array $outsideSiiGrid,
        public array $creditsDiffer,
        public float $progressSeconds,
    ) {}

    /**
     * Renglones de la historia que no pasaron sin explicación.
     */
    public function unexplainedRows(): int
    {
        return array_sum(array_column($this->unexplained, 'rows'));
    }

    /**
     * Lo que no cuadra: renglones sin explicar y alumnos completos en el SII que en Nexus no lo están o no están.
     */
    public function differences(): int
    {
        return $this->unexplainedRows() + count($this->incompleteInNexus) + count($this->missingFromNexus);
    }
}
