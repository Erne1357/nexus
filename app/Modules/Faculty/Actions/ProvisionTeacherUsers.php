<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Corre SyncTeacherAccess sobre todos los docentes, por bloques de 200, con un solo hash de la contraseña por defecto
 * para toda la corrida. También los borrados: a un docente borrado se le aplica la baja si no se le aplicó al
 * borrarlo (supuesto por confirmar), y si ya se le aplicó, queda sin cambios. Sin NEXUS_DEFAULT_PASSWORD falla
 * antes de tocar a ningún docente.
 *
 * Cada docente que cambia deja su propia bitácora (SyncTeacherAccess), y la corrida, una entrada más con las
 * cuentas y los números de empleado en conflicto (S15), aunque no haya cambiado nada. Con `$actor`, todas esas
 * entradas quedan a su nombre, también cuando corre en la cola (ProvisionTeacherUsersJob). Cada docente va en su
 * propia transacción: si la corrida se interrumpe, lo ya sincronizado se queda y repetirla es seguro.
 */
final class ProvisionTeacherUsers
{
    private const int CHUNK = 200;

    public function __construct(
        private readonly DefaultPassword $defaultPassword,
        private readonly SyncTeacherAccess $syncTeacherAccess,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @return array{created: int, linked: int, updated: int, unchanged: int, conflicts: list<string>}
     *
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(?User $actor = null): array
    {
        $passwordHash = $this->defaultPassword->hash();

        return $actor === null
            ? $this->run($passwordHash)
            : $this->causers->withCauser($actor, fn (): array => $this->run($passwordHash));
    }

    /**
     * @return array{created: int, linked: int, updated: int, unchanged: int, conflicts: list<string>}
     */
    private function run(string $passwordHash): array
    {
        $summary = ['created' => 0, 'linked' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => []];

        Teacher::withTrashed()
            ->select(['id', 'employee_number'])
            ->chunkById(self::CHUNK, function (Collection $teachers) use (&$summary, $passwordHash): void {
                foreach ($teachers as $teacher) {
                    $result = $this->syncTeacherAccess->handle($teacher, $passwordHash);

                    if ($result === AccessSync::Conflict) {
                        $summary['conflicts'][] = $teacher->employee_number;
                    } else {
                        $summary[$result->value]++;
                    }
                }
            });

        $total = $summary['created'] + $summary['linked'] + $summary['updated'] + $summary['unchanged'] + count($summary['conflicts']);

        activity()
            ->event('provisioned')
            ->withProperties($summary)
            ->log("Sincronizó el acceso de {$total} docentes: {$summary['created']} usuarios creados, {$summary['linked']} ligados, "
                ."{$summary['updated']} actualizados, {$summary['unchanged']} sin cambios y ".count($summary['conflicts']).' en conflicto');

        return $summary;
    }
}
