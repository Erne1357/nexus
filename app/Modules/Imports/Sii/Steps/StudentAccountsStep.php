<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Students\Jobs\SyncStudentAccountsJob;
use Illuminate\Support\Facades\Bus;

/**
 * Cuentas de los alumnos con su NIP del SII (AC-10 a AC-14, decisión del 11 de noviembre de 2026, confirmada). Lee de
 * `alumnos_todos` solo el número de control y el NIP, y los encola por bloques de `SyncStudentAccountsJob::CHUNK` en
 * un lote de la cola (`Bus::batch`, «Cuentas de alumnos del SII»), porque cifrar ~68,000 NIP con bcrypt tarda: así
 * se reparten entre los workers que haya. Cada bloque viaja cifrado (ShouldBeEncrypted) y crea o pone al día las
 * cuentas con SyncStudentAccounts; el resultado de cada bloque queda en la bitácora, no en este reporte.
 *
 * No pasa por el motor de importación: el NIP no debe quedar en el CSV intermedio de `_nexus/` ni en `import_rows`.
 * Escribe por su cuenta (SiiStep) y entrega un reporte (plantilla `student_accounts`; filas = alumnos con número de
 * control en la exportación, válidas = los que se encolan), con un aviso por los que no traen NIP (quedan sin acceso)
 * y una nota por los de NIP corto (se completan con ceros). Corre después de `alumnos` y `kardex`, cuando los alumnos
 * ya están en Nexus; uno que no se migró se cuenta en la bitácora del bloque. En un ensayo no encola nada y reporta
 * lo que encolaría. Nunca escribe un NIP en el reporte, las incidencias ni el log.
 */
final class StudentAccountsStep implements SiiStep
{
    /** @var list<string> las únicas columnas que se leen */
    public const array COLUMNS = ['no_de_control', 'nip'];

    public const string TEMPLATE = 'student_accounts';

    public function name(): string
    {
        return 'cuentas_alumnos';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        $export = $migration->export();
        $chunks = [];
        $chunk = [];
        $withoutNip = 0;
        $shortNip = 0;

        foreach ($export->reader(SiiFile::Alumnos)->rows(self::COLUMNS) as $row) {
            if ($row['no_de_control'] === '') {
                continue;
            }

            $withoutNip += $row['nip'] === '' ? 1 : 0;
            $shortNip += preg_match('/^\d{1,3}$/', $row['nip']) === 1 ? 1 : 0;
            $chunk[$row['no_de_control']] = $row['nip'];

            if (count($chunk) === SyncStudentAccountsJob::CHUNK) {
                $chunks[] = $chunk;
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        $students = array_sum(array_map('count', $chunks));

        if (! $migration->dryRun() && $chunks !== []) {
            Bus::batch(array_map(fn (array $nips): SyncStudentAccountsJob => new SyncStudentAccountsJob($nips, $migration->user()), $chunks))
                ->name('Cuentas de alumnos del SII')
                ->allowFailures()
                ->dispatch();
        }

        $verb = $migration->dryRun() ? 'Encolaría' : 'Encoló';
        $incidents = $withoutNip === 0 ? [] : [[
            'level' => 'warning',
            'ref' => 'nip',
            'message' => "{$withoutNip} alumno(s) sin NIP en el SII: su usuario queda sin acceso (AC-10).",
        ]];

        if ($shortNip > 0) {
            $incidents[] = ['level' => 'info', 'ref' => 'nip', 'message' => "{$shortNip} alumno(s) con NIP de 1 a 3 dígitos: se guarda con ceros a 4 y entran con las dos formas (AC-11)."];
        }

        yield new SiiBatchReport(
            self::TEMPLATE,
            $export->fileName(SiiFile::Alumnos),
            $students,
            $students,
            $withoutNip,
            0,
            0,
            0,
            $incidents,
            ["{$verb} las cuentas de {$students} alumno(s) en ".count($chunks).' bloque(s) en la cola («Cuentas de alumnos del SII»); lo que hizo cada bloque queda en la bitácora.'],
        );
    }
}
