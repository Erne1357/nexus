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
 * un lote de la cola `bulk` (`Bus::batch`, «Cuentas de alumnos del SII»), porque cifrar ~68,000 NIP con bcrypt tarda:
 * así se reparten entre los workers que haya sin dejar detrás los correos de la cola `default`. Cada bloque viaja
 * cifrado (ShouldBeEncrypted) y crea o pone al día las cuentas con SyncStudentAccounts; el resultado de cada bloque
 * queda en la bitácora, no en este reporte.
 *
 * No pasa por el motor de importación: el NIP no debe quedar en el CSV intermedio de `_nexus/` ni en `import_rows`.
 * Escribe por su cuenta (SiiStep) y entrega un reporte (plantilla `student_accounts`; filas = alumnos con número de
 * control en la exportación, válidas = los que se encolan), con un aviso por los que no traen NIP, una nota por los de
 * NIP corto (se completan con ceros) y un aviso por los números de control repetidos (vale el primer renglón, como en
 * `alumnos`). Si más de `MAX_EMPTY_NIP_SHARE` de los alumnos vienen sin NIP, la exportación perdió los NIP: no encola
 * nada y lo reporta como error (supuesto por confirmar). Corre después de `alumnos` y `kardex`, cuando los alumnos ya
 * están en Nexus; uno que no se migró se cuenta en la bitácora del bloque. En un ensayo no encola nada y reporta lo que
 * encolaría. Nunca escribe un NIP en el reporte, las incidencias ni el log.
 */
final class StudentAccountsStep implements SiiStep
{
    /** @var list<string> las únicas columnas que se leen */
    public const array COLUMNS = ['no_de_control', 'nip'];

    public const string TEMPLATE = 'student_accounts';

    /** La cola de los bloques; el worker la atiende después de `default` (compose.yaml). */
    public const string QUEUE = 'bulk';

    /** La fracción de alumnos sin NIP a partir de la cual el paso se detiene sin encolar. */
    public const float MAX_EMPTY_NIP_SHARE = 0.05;

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
        $nips = [];
        $repeated = 0;

        foreach ($export->reader(SiiFile::Alumnos)->rows(self::COLUMNS) as $row) {
            if ($row['no_de_control'] === '') {
                continue;
            }

            if (array_key_exists($row['no_de_control'], $nips)) {
                $repeated++;

                continue;
            }

            $nips[$row['no_de_control']] = $row['nip'];
        }

        $students = count($nips);
        $withoutNip = count(array_filter($nips, fn (string $nip): bool => $nip === ''));
        $shortNip = count(array_filter($nips, fn (string $nip): bool => preg_match('/^\d{1,3}$/', $nip) === 1));
        $chunks = array_chunk($nips, SyncStudentAccountsJob::CHUNK, preserve_keys: true);
        $stopped = $students > 0 && $withoutNip / $students > self::MAX_EMPTY_NIP_SHARE;

        if (! $migration->dryRun() && ! $stopped && $chunks !== []) {
            Bus::batch(array_map(fn (array $chunk): SyncStudentAccountsJob => new SyncStudentAccountsJob($chunk, $migration->user()), $chunks))
                ->name('Cuentas de alumnos del SII')
                ->onQueue(self::QUEUE)
                ->allowFailures()
                ->dispatch();
        }

        yield new SiiBatchReport(
            self::TEMPLATE,
            $export->fileName(SiiFile::Alumnos),
            $students,
            $stopped ? 0 : $students,
            $stopped ? 0 : $withoutNip,
            $stopped ? $students : 0,
            0,
            0,
            $this->incidents($students, $withoutNip, $shortNip, $repeated, $stopped),
            [$stopped
                ? 'No se encoló ninguna cuenta de alumno: la exportación trae sin NIP a más del '.(int) (self::MAX_EMPTY_NIP_SHARE * 100).' % de los alumnos.'
                : ($migration->dryRun() ? 'Encolaría' : 'Encoló')." las cuentas de {$students} alumno(s) en ".count($chunks).' bloque(s) en la cola «'.self::QUEUE.'» («Cuentas de alumnos del SII»); lo que hizo cada bloque queda en la bitácora.'],
        );
    }

    /**
     * @return list<array{level: 'error'|'warning'|'info', ref: string, message: string}>
     */
    private function incidents(int $students, int $withoutNip, int $shortNip, int $repeated, bool $stopped): array
    {
        $out = [];

        if ($stopped) {
            $out[] = ['level' => 'error', 'ref' => 'nip', 'message' => "{$withoutNip} de {$students} alumnos sin NIP en el SII, más del ".(int) (self::MAX_EMPTY_NIP_SHARE * 100).' %: la exportación parece haber perdido los NIP y no se encoló ninguna cuenta (supuesto por confirmar).'];
        } elseif ($withoutNip > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'nip', 'message' => "{$withoutNip} alumno(s) sin NIP en el SII: quien todavía no tiene contraseña queda sin acceso, y quien ya tiene su NIP lo conserva (AC-10)."];
        }

        if ($shortNip > 0) {
            $out[] = ['level' => 'info', 'ref' => 'nip', 'message' => "{$shortNip} alumno(s) con NIP de 1 a 3 dígitos: se guarda con ceros a 4 y entran con las dos formas (AC-11)."];
        }

        if ($repeated > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'no_de_control', 'message' => "{$repeated} renglón(es) con un número de control repetido: vale el primero, como en `alumnos`."];
        }

        return $out;
    }
}
