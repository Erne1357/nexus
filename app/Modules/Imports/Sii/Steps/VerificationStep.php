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
 * compara el original con Nexus y avisa si algo no cuadra). Es el último paso: con VerifySiiMigration compara el archivo
 * `sii_archivo` con el kárdex y el avance de Nexus y deja el resultado en el esquema `sii_verificacion`.
 *
 * Necesita el archivo de esta misma corrida (paso `archivo`), la historia del SII en la exportación y el kárdex aplicado
 * en esta corrida (paso `kardex`, que deja los mapas de claves que usa). Si falta algo, o si la corrida es un ensayo, no
 * verifica y lo dice. Nunca revierte nada de lo aplicado.
 *
 * Como ArchiveStep, no pasa por el motor de importación: entrega un reporte por tabla (plantilla `sii_verificacion`).
 * En `materias`, las filas son los renglones de la historia comparados, las advertencias los de créditos distintos y
 * los de una clave fuera de la retícula del SII que cuenta en Nexus, y los errores los que no pasaron sin explicación; en `alumnos`, las filas son los alumnos y los errores los completos
 * en el SII e incompletos en Nexus. Las altas son los renglones escritos. A las incidencias van el resumen (nivel
 * `info`), un error por alumno completo en el SII e incompleto en Nexus (con su número de control: `_incidencias.csv`
 * vive en `storage/`), un error por clave del SII de los renglones que no pasaron sin explicación, una advertencia por
 * clave del SII fuera de la retícula del alumno en el SII que cuenta en Nexus y una por materia con créditos distintos. A la consola, solo el resumen: nunca un número de control.
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
        $missing = $this->missing($migration);

        if ($missing !== null) {
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

        $verification = $this->verify->handle($subjectFor, $subjectForPlan);

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
        $errors = 0;
        $warnings = 0;

        foreach ($verification->unexplained as $group) {
            $errors += $group['rows'];
            $lines = $group['lines'].($group['rows'] > count(explode(', ', $group['lines'])) ? ', …' : '');
            $incidents[] = ['level' => 'error', 'ref' => $group['materia'], 'message' => "{$group['rows']} renglones de la historia con la clave {$group['materia']} no pasaron al kárdex de Nexus y no se sabe por qué (líneas {$lines}): revisa ".VerifySiiMigration::SCHEMA.'.materias.'];
        }

        foreach ($verification->outsideSiiGrid as $group) {
            $warnings += $group['rows'];
            $incidents[] = ['level' => 'warning', 'ref' => $group['materia'], 'message' => "La clave del SII {$group['materia']} no está en la retícula del alumno en el SII, pero llegó a {$group['subjects']}, que está en su plan de Nexus: cuenta en Nexus y no en el SII. {$group['rows']} renglones de la historia."];
        }

        foreach ($verification->creditsDiffer as $group) {
            $warnings += $group['rows'];
            $sii = $group['sii'] ?? 'no trae un número';
            $incidents[] = ['level' => 'warning', 'ref' => $group['materia'], 'message' => "La clave del SII {$group['materia']} llegó a {$group['subject']}, que en Nexus tiene {$group['nexus']} créditos y en la retícula del alumno en el SII {$sii}: {$group['rows']} renglones de la historia."];
        }

        $rows = $verification->historyRows;

        return new SiiBatchReport(VerifySiiMigration::SCHEMA, 'materias', $rows, $rows - $errors - $warnings, $warnings, $errors, $rows, 0, $incidents, $summary);
    }

    private function studentsReport(SiiVerification $verification): SiiBatchReport
    {
        $timing = 'El avance de los alumnos se recalculó antes de comparar: tardó '.number_format($verification->progressSeconds, 1, '.', '').' s.';
        $summary = ["Se compararon {$verification->students} alumnos del SII con su avance en Nexus (".VerifySiiMigration::SCHEMA.'.alumnos).'];

        foreach ($verification->statuses as $status) {
            $name = $status['status'] === '' ? '(vacío)' : $status['status'];
            $summary[] = "Estatus {$name} del SII: {$status['students']} alumnos, {$status['completeSii']} completos en el SII, {$status['completeNexus']} completos en Nexus, {$status['missing']} no están en Nexus.";
        }

        $incidents = [...$this->info($summary), ['level' => 'info', 'ref' => 'avance', 'message' => $timing]];

        foreach ($verification->incompleteInNexus as $student) {
            $nexus = $student['nexus'] === null ? 'sin avance' : "{$student['nexus']} de ".($student['planTotal'] ?? '?').' créditos';
            $lost = (float) $student['lost'] > 0 ? ": Nexus cuenta {$student['lost']} créditos menos que el SII." : '.';
            $incidents[] = ['level' => 'error', 'ref' => $student['control'], 'message' => "Completo en el SII ({$student['sii']} de {$student['required']} créditos de la retícula {$student['grid']}) e incompleto en Nexus ({$nexus} del plan {$student['plan']}){$lost}"];
        }

        $errors = count($verification->incompleteInNexus);
        $rows = $verification->students;

        return new SiiBatchReport(VerifySiiMigration::SCHEMA, 'alumnos', $rows, $rows - $errors, 0, $errors, $rows, 0, $incidents, [...$summary, $timing]);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{level: 'info', ref: string, message: string}>
     */
    private function info(array $lines): array
    {
        return array_map(fn (string $line): array => ['level' => 'info', 'ref' => 'resumen', 'message' => $line], $lines);
    }
}
