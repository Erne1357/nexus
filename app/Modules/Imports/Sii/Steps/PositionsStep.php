<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Imports\Sii\PlazaKeys;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\TeacherRoster;

/**
 * Plazas del SII, de `docentes2.claves_plazas` (ver `PlazaKeys` para el tipo y las horas de cada clave). Una fila
 * por docente migrado y tipo de plaza, con las horas sumadas: la plantilla `positions` tiene por clave el docente,
 * el tipo y «desde», así que varias plazas de asignatura de un docente son una sola fila con la suma. Las de apoyo
 * cuentan como cualquier otra (confirmado el 6 de noviembre de 2026). Sin vigencia, porque el SII no la trae
 * (supuesto por confirmar: vigentes sin fecha de inicio ni de fin).
 *
 * El RFC viejo de estatus 04 pasa al docente del RFC nuevo (`teacher_roster`); si los dos renglones están, se toman las
 * plazas del que las trae, de preferencia el del RFC nuevo. Se reporta por archivo y línea, sin RFC ni número de plaza:
 * la clave que no se entiende (toda o en parte), la categoría sin tipo, la asignatura sin horas o con más de 40, dos
 * plazas del mismo tipo fijo, un tipo cuyas plazas suman más de 40 y la suma que no cuadra con
 * `cantidad_horas_nombramiento`; agregados, los renglones de quien no se migró como docente y los docentes activos
 * sin plaza.
 */
final class PositionsStep implements SiiStep
{
    /** @var list<string> las únicas columnas de `docentes2` que lee este paso */
    public const array COLUMNS = ['rfc', 'claves_plazas', 'cantidad_horas_nombramiento'];

    public function name(): string
    {
        return 'plazas';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var TeacherRoster $roster */
        $roster = $migration->remember('teacher_roster', fn () => throw new SiiStepOutOfOrder($this->name(), 'teacher_roster', ['docentes']));

        [$rows, $incidents] = $this->positions($migration, $roster);

        yield new SiiBatch('positions', 'plazas.csv', $rows, incidents: $incidents);
    }

    /**
     * @return array{0: list<array<string, string|int>>, 1: list<array{level: 'error'|'warning', ref: string, message: string}>}
     */
    private function positions(SiiMigration $migration, TeacherRoster $roster): array
    {
        $export = $migration->export();
        $file = $export->fileName(SiiFile::Docentes2);
        /** @var array<string, list<array{line: int, own: bool, row: array<string, string>}>> $candidates renglones por RFC del docente en Nexus */
        $candidates = [];
        $unmigrated = 0;

        foreach ($export->reader(SiiFile::Docentes2)->rows(self::COLUMNS) as $line => $row) {
            $rfc = TeacherRoster::normalizeRfc($row['rfc']);
            $teacher = $roster->teacherFor[$rfc] ?? null;

            if ($teacher === null) {
                $unmigrated++;

                continue;
            }

            $candidates[$teacher][] = ['line' => $line, 'own' => $rfc === $teacher, 'row' => $row];
        }

        /** @var array<string, array<string, int>> $hoursByTeacher horas por RFC del docente en Nexus y tipo de plaza */
        $hoursByTeacher = [];
        $incidents = [];
        $repeated = 0;

        foreach ($candidates as $teacher => $rows) {
            $repeated += count($rows) - 1;
            $chosen = $this->chosen($rows);
            $hoursByTeacher[(string) $teacher] = $this->hoursByKind($chosen['row'], "{$file}:{$chosen['line']}", $incidents);
        }

        $rows = [];
        foreach ($hoursByTeacher as $teacher => $hoursByKind) {
            foreach (PositionKind::cases() as $kind) {
                if (isset($hoursByKind[$kind->value])) {
                    $rows[] = ['rfc' => $teacher, 'kind' => $kind->value, 'base_hours' => $hoursByKind[$kind->value], 'valid_from' => '', 'valid_to' => ''];
                }
            }
        }

        $withoutPlaza = count(array_filter($roster->rows, fn (array $row): bool => $row['active'] === 'Sí' && ($hoursByTeacher[$row['rfc']] ?? []) === []));

        foreach ([
            [$unmigrated, 'renglones de docentes2 no son de un docente migrado (administrativos o sin departamento): sus plazas no se migran.'],
            [$repeated, 'renglones de docentes2 repiten un docente (RFC viejo y nuevo): se toman las plazas del renglón que las trae, de preferencia el del RFC nuevo.'],
            [$withoutPlaza, 'docentes activos no tienen plaza en docentes2: quedan sin horas base.'],
        ] as [$count, $text]) {
            if ($count > 0) {
                $incidents[] = $this->warning('plazas', "{$count} {$text}");
            }
        }

        return [$rows, $incidents];
    }

    /**
     * De los renglones de un mismo docente, el primero que trae claves, de preferencia el de su RFC en Nexus (el nuevo
     * si hubo cambio de RFC); si ninguno trae claves, el primero.
     *
     * @param  list<array{line: int, own: bool, row: array<string, string>}>  $rows
     * @return array{line: int, own: bool, row: array<string, string>}
     */
    private function chosen(array $rows): array
    {
        $ranked = $rows;
        usort($ranked, fn (array $a, array $b): int => [trim($a['row']['claves_plazas']) === '', ! $a['own']] <=> [trim($b['row']['claves_plazas']) === '', ! $b['own']]);

        return $ranked[0];
    }

    /**
     * Las horas por tipo de plaza de un renglón de docentes2, con sus avisos por línea.
     *
     * @param  array<string, string>  $row
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array<string, int>
     */
    private function hoursByKind(array $row, string $ref, array &$incidents): array
    {
        $plazas = PlazaKeys::parse($row['claves_plazas']);

        if ($plazas === []) {
            if (trim($row['claves_plazas']) !== '') {
                $incidents[] = $this->warning($ref, 'La clave de plaza de esta línea no se entiende: el docente queda sin plaza.');
            }

            return [];
        }

        if (PlazaKeys::leftover($row['claves_plazas']) !== '') {
            $incidents[] = $this->warning($ref, 'Parte de la clave de plaza de esta línea no se entiende: esa parte no se migra.');
        }

        $hours = [];
        foreach ($plazas as $plaza) {
            $kind = $plaza['kind'];

            if ($kind === null) {
                $incidents[] = $this->warning($ref, "La plaza de categoría {$plaza['category']} de esta línea no tiene tipo en Nexus: no se migra.");
            } elseif ($plaza['hours'] === null) {
                $incidents[] = $this->warning($ref, "La plaza de asignatura {$plaza['category']} de esta línea no trae horas en la clave: no se migra.");
            } elseif ($plaza['hours'] > PositionKind::MAX_HOURS) {
                $incidents[] = $this->warning($ref, "La plaza {$plaza['category']} de esta línea trae {$plaza['hours']} horas en la clave, más de las ".PositionKind::MAX_HOURS.' de una plaza: no se migra.');
            } elseif ($kind->fixedHours() !== null && isset($hours[$kind->value])) {
                $incidents[] = $this->warning($ref, "El docente de esta línea trae dos plazas de {$kind->label()}: se migra una.");
            } else {
                $hours[$kind->value] = ($hours[$kind->value] ?? 0) + $plaza['hours'];
            }
        }

        foreach ($hours as $value => $sum) {
            if ($sum > PositionKind::MAX_HOURS) {
                $incidents[] = $this->warning($ref, 'Las plazas de '.PositionKind::from($value)->label()." de esta línea suman {$sum} horas, más de las ".PositionKind::MAX_HOURS.' de una plaza: no se migran.');
                unset($hours[$value]);
            }
        }

        $total = array_sum($hours);
        $declared = trim($row['cantidad_horas_nombramiento']);
        if (is_numeric($declared) && (float) $declared !== (float) $total) {
            $incidents[] = $this->warning($ref, "Las plazas de esta línea suman {$total} horas y el SII dice {$declared} (cantidad_horas_nombramiento).");
        }

        return $hours;
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
