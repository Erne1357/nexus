<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Corre SyncTeacherAccess sobre todos los docentes, por bloques de 200. Los usuarios que crea nacen sin acceso (AC-02),
 * así que no necesita la contraseña por defecto. También los borrados: a un docente borrado se le aplica la baja si
 * no se le aplicó al borrarlo (supuesto por confirmar), y si ya se le aplicó, queda sin cambios.
 *
 * Cada docente que cambia deja su propia bitácora (SyncTeacherAccess), y la corrida, una entrada más con las
 * cuentas y los RFC en conflicto (S15), aunque no haya cambiado nada. Con `$actor`, todas esas
 * entradas quedan a su nombre, también cuando corre en la cola (ProvisionTeacherUsersJob). Cada docente va en su
 * propia transacción: si la corrida se interrumpe, lo ya sincronizado se queda y repetirla es seguro.
 */
final class ProvisionTeacherUsers
{
    private const int CHUNK = 200;

    public function __construct(
        private readonly SyncTeacherAccess $syncTeacherAccess,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @return array{created: int, linked: int, updated: int, unchanged: int, conflicts: list<string>}
     */
    public function handle(?User $actor = null): array
    {
        return $actor === null
            ? $this->run()
            : $this->causers->withCauser($actor, fn (): array => $this->run());
    }

    /**
     * @return array{created: int, linked: int, updated: int, unchanged: int, conflicts: list<string>}
     */
    private function run(): array
    {
        $summary = ['created' => 0, 'linked' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => []];

        Teacher::withTrashed()
            ->select(['id', 'rfc'])
            ->chunkById(self::CHUNK, function (Collection $teachers) use (&$summary): void {
                foreach ($teachers as $teacher) {
                    $result = $this->syncTeacherAccess->handle($teacher);

                    if ($result === AccessSync::Conflict) {
                        $summary['conflicts'][] = $teacher->rfc;
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
