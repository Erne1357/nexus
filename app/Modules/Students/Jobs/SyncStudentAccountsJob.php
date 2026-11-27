<?php

namespace App\Modules\Students\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un bloque de cuentas de alumnos en la cola (SyncStudentAccounts, AC-10): cifrar con bcrypt ~68,000 NIP tarda, así
 * que la migración del SII los reparte en bloques que se pueden repartir entre varios workers. Lleva los NIP del bloque
 * por número de control, y por eso es ShouldBeEncrypted: el payload en la cola (y en `failed_jobs`, si falla) va
 * cifrado con la llave de la aplicación, nunca en claro.
 *
 * Es idempotente: si falla a medias, reintentarlo es seguro (lo ya escrito queda sin cambios). Si su lote se
 * cancela, no hace nada.
 */
final class SyncStudentAccountsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Queueable;

    /** Alumnos por bloque: a costo 10, un bloque tarda unos 10 a 20 segundos. */
    public const int CHUNK = 250;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, string>  $nips  el NIP del SII por número de control; '' si el SII no trae
     */
    public function __construct(#[\SensitiveParameter] public array $nips, public ?User $actor = null) {}

    public function handle(SyncStudentAccounts $sync): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $sync->handle($this->nips, $this->actor);
    }
}
