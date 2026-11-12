<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nombra a una persona coordinadora de varias carreras en una modalidad, en un campus o en todos (sin campus), del día
 * `$from` al `$to` (M5, confirmado el 12 de noviembre de 2026: un coordinador puede coordinar varias carreras). Crea un
 * nombramiento por carrera con AppointRole, todos en una transacción (MS19, supuesto por confirmar): la tabla no cambia
 * de forma, terminar uno no toca los otros y, si una carrera no sirve, no se nombra ninguna. Una carrera repetida en
 * la lista cuenta una vez. Cada nombramiento deja su bitácora y su aviso como cualquier otro de AppointRole. Quién
 * puede nombrar lo decide RoleAssignmentPolicy antes de llamarla.
 */
final class AppointCareerCoordinator
{
    public function __construct(private readonly AppointRole $appointRole) {}

    /**
     * @param  list<string>  $careerIds
     * @return list<RoleAssignment> en el orden de `$careerIds`
     *
     * @throws ValidationException
     */
    public function handle(User $user, Modality $modality, ?Campus $campus, array $careerIds, CarbonImmutable $from, ?CarbonImmutable $to = null): array
    {
        $careerIds = array_values(array_unique($careerIds));

        if ($careerIds === []) {
            throw ValidationException::withMessages(['career' => 'Elige al menos una carrera.']);
        }

        return DB::transaction(fn (): array => array_map(
            fn (string $careerId): RoleAssignment => $this->appointRole->handle($user, Role::Coordinador, $campus, null, $careerId, $from, $to, $modality),
            $careerIds,
        ));
    }
}
