<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\StudentTally;
use App\Modules\Imports\Sii\SubjectName;
use Generator;

/**
 * Alumnos del SII (D5, D11 y D12 confirmados el 5 de noviembre de 2026; D13 confirmado para los aspirantes, y el
 * resto, supuestos por confirmar). Se migran todos los renglones cuya retícula está en `plan_for` (plan, carrera y
 * modalidad salen de ahí); campus `1` es C1 y `2` es C2. Estatus: ACT e INS son `activo`; BT1 a BT4, `baja_temporal`;
 * BD1 a BD9, BCP, BDF y TRA, `baja_definitiva`; EGR y TIT, `egresado`; el motivo es siempre el código del SII. Cuentan
 * como activos ACT, INS y las bajas temporales. El aspirante (ASP) se migra inactivo y sin estatus (el «cómo» es
 * supuesto por confirmar), igual que un código que no se conoce, que además deja un aviso por código.
 *
 * Solo pide a `SiiCsvReader` las columnas de `COLUMNS`: el NIP, la CURP, la fecha de nacimiento y los domicilios se
 * descartan al leer el renglón y nunca llegan aquí. Lo que no se emite se cuenta en avisos agregados sin números de
 * control ni nombres. Un solo lote `students`; los renglones salen en streaming.
 */
final class StudentsStep implements SiiStep
{
    /** @var list<string> */
    public const array COLUMNS = [
        'no_de_control', 'carrera', 'reticula', 'nivel_escolar', 'semestre', 'estatus_alumno',
        'apellido_paterno', 'apellido_materno', 'nombre_alumno', 'periodo_ingreso_it', 'campus',
    ];

    private const array CAMPUSES = ['1' => 'C1', '2' => 'C2'];

    private const array STATUSES = [
        'ACT' => 'activo', 'INS' => 'activo',
        'BT1' => 'baja_temporal', 'BT2' => 'baja_temporal', 'BT3' => 'baja_temporal', 'BT4' => 'baja_temporal',
        'BD1' => 'baja_definitiva', 'BD2' => 'baja_definitiva', 'BD3' => 'baja_definitiva', 'BD4' => 'baja_definitiva',
        'BD5' => 'baja_definitiva', 'BD6' => 'baja_definitiva', 'BD7' => 'baja_definitiva', 'BD8' => 'baja_definitiva',
        'BD9' => 'baja_definitiva', 'BCP' => 'baja_definitiva', 'BDF' => 'baja_definitiva', 'TRA' => 'baja_definitiva',
        'EGR' => 'egresado', 'TIT' => 'egresado',
    ];

    private const array ACTIVE = ['activo', 'baja_temporal'];

    public function name(): string
    {
        return 'alumnos';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var array<string, array{plan: string, career: string, modality: string}> $planFor */
        $planFor = $migration->remember('plan_for', fn () => throw new SiiStepOutOfOrder($this->name(), 'plan_for', ['carreras']));

        $tally = new StudentTally;

        foreach ($this->rows($migration, $planFor, $tally) as $ignored) {
            // Primera pasada: solo cuenta lo que se descarta o se corrige, para dar los avisos antes del lote.
        }

        yield new SiiBatch('students', 'alumnos.csv', $this->rows($migration, $planFor, new StudentTally), incidents: $this->incidents($tally));
    }

    /**
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @return Generator<int, array<string, string>>
     */
    private function rows(SiiMigration $migration, array $planFor, StudentTally $tally): Generator
    {
        foreach ($migration->export()->reader(SiiFile::Alumnos)->rows(self::COLUMNS) as $row) {
            $key = $row['carrera'].'/'.$row['reticula'];

            if (! isset($planFor[$key])) {
                $tally->unknownPlans[$key] = ($tally->unknownPlans[$key] ?? 0) + 1;

                continue;
            }

            $campus = self::CAMPUSES[$row['campus']] ?? null;

            if ($campus === null) {
                $label = $row['campus'] === '' ? 'vacío' : mb_substr($row['campus'], 0, 20);
                $tally->unknownCampuses[$label] = ($tally->unknownCampuses[$label] ?? 0) + 1;

                continue;
            }

            $name = SubjectName::title(implode(' ', array_filter([$row['nombre_alumno'], $row['apellido_paterno'], $row['apellido_materno']], fn (string $part): bool => $part !== '')));

            if ($name === '' || $row['no_de_control'] === '') {
                $tally->nameless++;

                continue;
            }

            $code = strtoupper($row['estatus_alumno']);
            $status = self::STATUSES[$code] ?? '';

            if ($status === '' && $code !== 'ASP') {
                $label = $code === '' ? 'vacío' : mb_substr($code, 0, 20);
                $tally->unknownStatuses[$label] = ($tally->unknownStatuses[$label] ?? 0) + 1;
            }

            $semester = $row['semestre'];
            $validSemester = ctype_digit($semester) && (int) $semester >= 1 && (int) $semester <= 20;

            if (! $validSemester && $semester !== '') {
                $tally->badSemesters++;
            }

            yield [
                'control_number' => $row['no_de_control'],
                'name' => $name,
                'career' => $planFor[$key]['career'],
                'study_plan' => $planFor[$key]['plan'],
                'campus' => $campus,
                'semester' => $validSemester ? (string) (int) $semester : '',
                'status' => $status,
                'modality' => $planFor[$key]['modality'],
                'status_reason' => $code,
                'active' => in_array($status, self::ACTIVE, true) ? 'Sí' : 'No',
            ];
        }
    }

    /**
     * @return list<array{level: 'error'|'warning', ref: string, message: string}>
     */
    private function incidents(StudentTally $tally): array
    {
        $out = [];

        ksort($tally->unknownPlans);
        foreach ($tally->unknownPlans as $key => $count) {
            $out[] = ['level' => 'warning', 'ref' => (string) $key, 'message' => "{$count} alumno(s) de la retícula {$key}, que no está en el curado o está excluida: no se migran."];
        }

        ksort($tally->unknownCampuses);
        foreach ($tally->unknownCampuses as $label => $count) {
            $out[] = ['level' => 'warning', 'ref' => 'campus '.$label, 'message' => "{$count} alumno(s) con campus {$label}, que no es 1 ni 2: no se migran."];
        }

        ksort($tally->unknownStatuses);
        foreach ($tally->unknownStatuses as $label => $count) {
            $out[] = ['level' => 'warning', 'ref' => 'estatus '.$label, 'message' => "{$count} alumno(s) con estatus {$label}, que no es de los 20 conocidos: se migran sin estatus (supuesto por confirmar)."];
        }

        if ($tally->badSemesters > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'semestre', 'message' => "{$tally->badSemesters} alumno(s) con semestre fuera de 1 a 20: se migran sin semestre (supuesto por confirmar)."];
        }

        if ($tally->nameless > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'nombre', 'message' => "{$tally->nameless} alumno(s) sin nombre o sin número de control: no se migran (supuesto por confirmar)."];
        }

        return $out;
    }
}
