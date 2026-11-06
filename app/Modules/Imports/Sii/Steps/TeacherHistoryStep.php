<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\TeacherRoster;
use Generator;

/**
 * Historial docente del SII: una fila por grupo con docente migrado, con el RFC ya fundido (`teacher_for_rfc`), la
 * materia de Nexus (`subject_for`), el periodo y el grupo del SII, y el campus vacío (D2: el SII no lo dice).
 * Los grupos sin RFC, de un RFC que no se migró o de una materia sin equivalente en Nexus no se emiten; cada
 * motivo deja un aviso agregado (las materias, uno por clave del SII con su conteo).
 */
final class TeacherHistoryStep implements SiiStep
{
    public function name(): string
    {
        return 'historial_docente';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var TeacherRoster $roster */
        $roster = $migration->remember('teacher_roster', fn () => throw new SiiStepOutOfOrder($this->name(), 'teacher_roster', ['docentes']));

        /** @var array<string|int, string> $subjectFor */
        $subjectFor = $migration->remember('subject_for', fn () => throw new SiiStepOutOfOrder($this->name(), 'subject_for', ['materias']));

        $skipped = ['parallel' => 0, 'long' => 0, 'repeated' => 0];
        foreach ($this->rows($migration, $roster, $subjectFor, $skipped) as $unused) {
            // Primera pasada: solo cuenta lo que no se emite, para que el aviso salga en el lote.
        }

        yield new SiiBatch('teacher_history', 'historial_docente.csv', $this->rows($migration, $roster, $subjectFor), incidents: $this->incidents($roster, $subjectFor, $skipped));
    }

    /**
     * @param  array<string|int, string>  $subjectFor
     * @param  array{parallel: int, long: int, repeated: int}  $skipped
     * @return list<array{level: 'error'|'warning', ref: string, message: string}>
     */
    private function incidents(TeacherRoster $roster, array $subjectFor, array $skipped): array
    {
        $missing = [];

        foreach ($roster->cells as $periods) {
            foreach ($periods as $materias) {
                foreach ($materias as $materia => $count) {
                    if (! isset($subjectFor[(string) $materia])) {
                        $missing[(string) $materia] = ($missing[(string) $materia] ?? 0) + $count;
                    }
                }
            }
        }

        ksort($missing);
        $incidents = [];

        foreach ($missing as $materia => $count) {
            $incidents[] = ['level' => 'warning', 'ref' => $materia, 'message' => "La materia {$materia} del SII no tiene equivalente en Nexus: {$count} grupos no pasan al historial."];
        }

        foreach ([
            [$roster->withoutRfc, 'grupos del SII no traen RFC: no pasan al historial.'],
            [$skipped['parallel'], 'grupos paralelos cuentan una vez (mismo docente y periodo que su grupo padre): no se repiten en el historial (R18).'],
            [$skipped['long'], 'grupos con clave de más de 20 caracteres (materia del SII + grupo): no pasan al historial.'],
            [$skipped['repeated'], 'grupos repetidos (mismo docente, materia de Nexus, periodo y clave): se emite uno solo.'],
            [$roster->unmigratedGroups, 'grupos son de un RFC que no se migró (administrativos o sin departamento): no pasan al historial.'],
        ] as [$count, $text]) {
            if ($count > 0) {
                $incidents[] = ['level' => 'warning', 'ref' => 'historial', 'message' => "{$count} {$text}"];
            }
        }

        return $incidents;
    }

    /**
     * Una fila por grupo. El `group_code` lleva la materia del SII como prefijo (`<materia>-<grupo>`) porque al
     * unificar las claves de materia dos grupos distintos pueden compartir letra (R18). Un grupo paralelo de otro
     * del mismo docente y periodo no se emite; los exactamente repetidos se emiten una vez.
     *
     * @param  array<string|int, string>  $subjectFor
     * @param  array{parallel: int, long: int, repeated: int}|null  $skipped  se llena con lo que no se emite
     * @return Generator<int, array<string, string>>
     */
    private function rows(SiiMigration $migration, TeacherRoster $roster, array $subjectFor, ?array &$skipped = null): Generator
    {
        $seen = [];

        foreach ($migration->export()->reader(SiiFile::Grupos)->rows() as $row) {
            $teacher = $roster->teacherFor[TeacherRoster::normalizeRfc($row['rfc'])] ?? null;
            $subject = $subjectFor[$row['materia']] ?? null;

            if ($teacher === null || $subject === null) {
                continue;
            }

            $parent = TeacherRoster::parentKey($row['paralelo_de']);
            if ($parent !== null && $parent !== TeacherRoster::groupKey($row['materia'], $row['grupo']) && isset($roster->groupKeys[$teacher][$row['periodo']][$parent])) {
                $skipped['parallel'] = ($skipped['parallel'] ?? 0) + 1;

                continue;
            }

            $group = trim($row['materia']).'-'.trim($row['grupo']);
            if (mb_strlen($group) > 20) {
                $skipped['long'] = ($skipped['long'] ?? 0) + 1;

                continue;
            }

            $key = "{$teacher}|{$subject}|{$row['periodo']}|{$group}";
            if (isset($seen[$key])) {
                $skipped['repeated'] = ($skipped['repeated'] ?? 0) + 1;

                continue;
            }
            $seen[$key] = true;

            yield [
                'rfc' => $teacher,
                'subject_code' => $subject,
                'term_code' => $row['periodo'],
                'campus_code' => '',
                'group_code' => $group,
            ];
        }
    }
}
