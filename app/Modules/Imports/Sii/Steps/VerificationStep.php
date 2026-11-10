<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Actions\VerifySiiMigration;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiVerification;

/**
 * Verificación de la corrida contra el SII original (decisión del 10 de noviembre de 2026, confirmada: cada corrida
 * compara el original con Nexus y avisa si algo no cuadra). Es el último paso: con VerifySiiMigration compara el
 * archivo `sii_archivo` con el kárdex y el avance de Nexus y deja el resultado en el esquema `sii_verificacion`.
 *
 * Necesita el archivo de esta misma corrida (paso `archivo`), la historia del SII en la exportación y el kárdex
 * aplicado en esta corrida (paso `kardex`, que deja los mapas de claves que usa). Si falta algo, no verifica, lo dice
 * y, en una corrida real, deja `sii_verificacion` vacía con la razón en `_corrida`. En un ensayo no toca nada. Nunca
 * revierte nada de lo aplicado.
 *
 * Como ArchiveStep, no pasa por el motor de importación: entrega un reporte por tabla (plantilla `sii_verificacion`).
 * - `materias`: filas = renglones de la historia comparados; advertencias = los renglones de las tres advertencias
 *   de abajo; errores = los que no pasaron sin explicación.
 * - `alumnos`: filas = alumnos; errores = completos en el SII que en Nexus están incompletos o no están (si su
 *   retícula se migra).
 * Las altas son los renglones escritos y las diferencias, los errores.
 *
 * Incidencias:
 * - el resumen (nivel `info`);
 * - un error por alumno completo en el SII e incompleto o ausente en Nexus, con su número de control
 *   (`_incidencias.csv` vive en `storage/`);
 * - un error por clave del SII de los renglones que no pasaron sin explicación;
 * - una advertencia por clave del SII aprobada en la retícula del alumno en el SII que en Nexus no cuenta, una por
 *   clave fuera de esa retícula que en Nexus sí cuenta y una por materia con créditos distintos.
 * A la consola va solo el resumen: nunca un número de control.
 */
final class VerificationStep implements SiiStep
{
    public function __construct(private readonly VerifySiiMigration $verify) {}

    public function name(): string
    {
        return 'verificacion';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        $dir = $migration->export()->dir();
        $missing = $this->missing($migration);

        if ($missing !== null) {
            if (! $migration->dryRun()) {
                $this->verify->skip($dir, $missing);
            }

            $message = "No se verificó la migración contra el SII: {$missing}";

            yield new SiiBatchReport(VerifySiiMigration::SCHEMA, 'sin verificar', 0, 0, 0, 0, 0, 0,
                incidents: [['level' => 'warning', 'ref' => $this->name(), 'message' => $message]],
                notes: [$message],
            );

            return;
        }

        /** @var array<string|int, string> $subjectFor */
        $subjectFor = $migration->remember('subject_for', fn (): array => []);
        /** @var array<string, array<string|int, string>> $subjectForPlan */
        $subjectForPlan = $migration->remember('subject_for_plan', fn (): array => []);
        /** @var array<string, mixed> $planFor */
        $planFor = $migration->remember('plan_for', fn (): array => []);

        $verification = $this->verify->handle($dir, $subjectFor, $subjectForPlan, $planFor);

        yield $this->subjectsReport($verification);
        yield $this->studentsReport($verification);
    }

    /**
     * Por qué no se puede verificar esta corrida, o null si sí se puede.
     */
    private function missing(SiiMigration $migration): ?string
    {
        return match (true) {
            $migration->dryRun() => 'la corrida es un ensayo (--dry-run) y nada se escribió.',
            ! $migration->remember('archived', fn (): bool => false) => 'el paso archivo no corrió en esta corrida, así que sii_archivo no es esta exportación (corre la migración completa o agrega --only=archivo).',
            ! $migration->export()->has(SiiFile::HistoriaAlumno) => 'la exportación no trae historia_alumno: no hay kárdex del SII contra qué comparar.',
            ! $migration->remember('kardex_applied', fn (): bool => false) => 'el paso kardex no corrió en esta corrida, así que el kárdex de Nexus no es el de esta exportación (agrega --only=kardex).',
            default => null,
        };
    }

    private function subjectsReport(SiiVerification $verification): SiiBatchReport
    {
        $summary = ["Se compararon {$verification->historyRows} renglones de la historia del SII con el kárdex de Nexus (".VerifySiiMigration::SCHEMA.'.materias).'];

        foreach ($verification->diagnoses as $diagnosis => $count) {
            $summary[] = "«{$diagnosis}»: {$count} renglones.";
        }

        $incidents = $this->info($summary);
        $warnings = 0;

        foreach ($verification->unexplained as $group) {
            $lines = $group['lines'].($group['rows'] > count(explode(', ', $group['lines'])) ? ', …' : '');
            $incidents[] = $this->issue('error', $group['materia'], "{$group['rows']} renglones de la historia con la clave {$group['materia']} no pasaron al kárdex de Nexus y no se sabe por qué (líneas {$lines}): revisa ".VerifySiiMigration::SCHEMA.'.materias.');
        }

        foreach ($verification->outsidePlanApproved as $group) {
            $warnings += $group['rows'];
            $incidents[] = $this->issue('warning', $group['materia'], "La clave del SII {$group['materia']} está en la retícula del alumno en el SII y está aprobada, pero llegó a {$group['subjects']}, que no está en la retícula de su plan en Nexus: cuenta en el SII y no en Nexus. {$group['rows']} renglones de la historia.");
        }

        foreach ($verification->outsideSiiGrid as $group) {
            $warnings += $group['rows'];
            $incidents[] = $this->issue('warning', $group['materia'], "La clave del SII {$group['materia']} no está en la retícula del alumno en el SII, pero llegó a {$group['subjects']}, que está en su plan de Nexus: cuenta en Nexus y no en el SII. {$group['rows']} renglones de la historia.");
        }

        foreach ($verification->creditsDiffer as $group) {
            $warnings += $group['rows'];
            $sii = $group['sii'] ?? 'no trae un número';
            $incidents[] = $this->issue('warning', $group['materia'], "La clave del SII {$group['materia']} llegó a {$group['subject']}, que en Nexus tiene {$group['nexus']} créditos y en la retícula del alumno en el SII {$sii}: {$group['rows']} renglones de la historia.");
        }

        $rows = $verification->historyRows;
        $errors = $verification->unexplainedRows();

        return new SiiBatchReport(VerifySiiMigration::SCHEMA, 'materias', $rows, $rows - $errors - $warnings, $warnings, $errors, $rows, 0, $incidents, $summary, $errors);
    }

    private function studentsReport(SiiVerification $verification): SiiBatchReport
    {
        $timing = 'El avance de los alumnos se recalculó antes de comparar: tardó '.number_format($verification->progressSeconds, 1, '.', '').' s.';
        $summary = ["Se compararon {$verification->students} alumnos del SII con su avance en Nexus (".VerifySiiMigration::SCHEMA.'.alumnos).'];

        foreach ($verification->statuses as $status) {
            $name = $status['status'] === '' ? '(vacío)' : $status['status'];
            $summary[] = "Estatus {$name} del SII: {$status['students']} alumnos, {$status['completeSii']} completos en el SII, {$status['completeNexus']} completos en Nexus, {$status['missing']} no están en Nexus ({$status['excluded']} de retículas que no se migran).";
        }

        $summary[] = "{$verification->lostCredits} alumnos incompletos en Nexus cuentan menos créditos en Nexus que en el SII.";
        $incidents = [...$this->info($summary), $this->issue('info', 'avance', $timing)];

        foreach ($verification->incompleteInNexus as $student) {
            $nexus = $student['nexus'] === null ? 'sin avance' : "{$student['nexus']} de ".($student['planTotal'] ?? '?').' créditos';
            $lost = (float) $student['lost'] > 0 ? ": Nexus cuenta {$student['lost']} créditos menos que el SII." : '.';
            $incidents[] = $this->issue('error', $student['control'], "Completo en el SII ({$student['sii']} de {$student['required']} créditos de la retícula {$student['grid']}) e incompleto en Nexus ({$nexus} del plan {$student['plan']}){$lost}");
        }

        foreach ($verification->missingFromNexus as $student) {
            $incidents[] = $this->issue('error', $student['control'], "Completo en el SII ({$student['sii']} de {$student['required']} créditos de la retícula {$student['grid']}), que se migra, y no está en Nexus.");
        }

        $errors = count($verification->incompleteInNexus) + count($verification->missingFromNexus);
        $rows = $verification->students;

        return new SiiBatchReport(VerifySiiMigration::SCHEMA, 'alumnos', $rows, $rows - $errors, 0, $errors, $rows, 0, $incidents, [...$summary, $timing], $errors);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{level: 'info', ref: string, message: string}>
     */
    private function info(array $lines): array
    {
        return array_map(fn (string $line): array => $this->issue('info', 'resumen', $line), $lines);
    }

    /**
     * @param  'error'|'warning'|'info'  $level
     * @return array{level: 'error'|'warning'|'info', ref: string, message: string}
     */
    private function issue(string $level, string $ref, string $message): array
    {
        return ['level' => $level, 'ref' => $ref, 'message' => $message];
    }
}
