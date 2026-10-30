<?php

namespace App\Modules\Faculty\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\ProvisionTeacherUsers;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sincroniza en la cola el acceso de todos los docentes (ProvisionTeacherUsers), a nombre de `$actor` si se da.
 * Lo despachan el botón «Dar de alta usuarios» de Docentes y la plantilla `teachers` al aplicar.
 *
 * Es único (ShouldBeUniqueUntilProcessing extiende ShouldBeUnique): espera uno solo en la cola aunque lo pidan
 * varias veces, con un candado que vence en una hora por si nunca llega a correr. Si lo piden mientras corre, se
 * encola de nuevo, porque pudo llegar un docente nuevo, pero no se encima con la corrida en curso: espera a que
 * termine y se reintenta cada 30 segundos durante una hora. Sincronizar es idempotente, así que reintentar es
 * seguro; un error de verdad (como la falta de NEXUS_DEFAULT_PASSWORD) lo deja fallido a la primera
 * (`maxExceptions`). Media hora de tiempo máximo, por debajo del `retry_after` de la conexión.
 */
final class ProvisionTeacherUsersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public ?User $actor = null) {}

    public function handle(ProvisionTeacherUsers $provision): void
    {
        $provision->handle($this->actor);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('teacher-provisioning'))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
