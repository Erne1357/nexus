<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Rules\UserAccountRules;

/**
 * La vista previa de «Dar de baja» (CF-05, CF-09) para el cajón de la ficha: los nombramientos que DeactivateUser
 * terminaría hoy o quitaría (RoleAssignmentRules::accountAssignments(), sin el de docente ni el de alumno), cuáles son
 * de un puesto exclusivo (AZ-S7, `Role::isExclusive()`) que se quedaría sin titular, si también es docente y en qué
 * departamentos está adscrito, si dejaría algún día sin admin vigente (AZ-S12) y por qué no se puede dar de baja.
 *
 * Un puesto exclusivo no admite dos titulares en la misma fecha (`role_assignments_one_jefe`,
 * `role_assignments_one_subdireccion`), así que nadie más lo ocupa en los días que el nombramiento deja: se queda sin
 * titular hasta que se nombre a otra persona. Core no conoce a los docentes: sus departamentos salen de
 * UnitAdscriptions, que Faculty liga (el id de un departamento es el de su unidad).
 */
final class DeactivationPreviewQuery
{
    public function __construct(
        private readonly RoleAssignmentRules $assignmentRules,
        private readonly UserAccountRules $rules,
        private readonly AssignmentLabels $labels,
        private readonly UnitAdscriptions $adscriptions,
    ) {}

    public function for(User $person, User $actor): DeactivationPreview
    {
        $assignments = $this->assignmentRules->accountAssignments($person)->get();
        $labels = $this->labels->describe($assignments);
        $today = today()->toDateString();
        $state = $this->rules->deactivationStateBlocker($person, $actor);
        $gap = $person->is_active ? $this->rules->adminGap($person) : null;

        return new DeactivationPreview(
            person: $person,
            assignments: $assignments->map(fn (RoleAssignment $assignment): array => [
                'summary' => $labels[$assignment->getKey()]->summary,
                'removed' => $assignment->valid_from->toDateString() > $today,
                'vacates' => $assignment->role->isExclusive(),
            ])->values()->all(),
            teacherDepartments: $this->assignmentRules->isTeacher($person) ? $this->departmentsOf($person) : null,
            adminGap: $gap,
            blocker: $state ?? $gap,
        );
    }

    /**
     * Los departamentos a los que está adscrito como docente, por clave.
     *
     * @return list<array{code: string, name: string}>
     */
    private function departmentsOf(User $person): array
    {
        $unitIds = array_keys(array_filter(
            $this->adscriptions->userIdsByUnit(),
            fn (array $userIds): bool => in_array($person->getKey(), $userIds, true),
        ));

        if ($unitIds === []) {
            return [];
        }

        return OrgUnit::withTrashed()->whereKey($unitIds)->orderBy('code')->get(['code', 'name'])
            ->map(fn (OrgUnit $unit): array => ['code' => (string) $unit->code, 'name' => (string) $unit->name])
            ->values()
            ->all();
    }
}
