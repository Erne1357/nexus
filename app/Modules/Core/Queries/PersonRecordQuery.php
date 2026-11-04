<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AssignmentTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * La ficha de una persona en «Personas y puestos» (PersonRecord). Los nombramientos salen ordenados (vigentes por
 * inicio descendente, programados por inicio ascendente y terminados por fin descendente) y cada uno con su estado
 * de hoy, los días que le faltan, quién lo terminó (la entrada de bitácora con su `role_assignment_id`) y, si aquí no
 * se puede terminar, por qué: el docente se maneja en la ficha del docente (AZ-S11), y uno de
 * Administración no se termina si quedaría algún día sin admin vigente (AZ-S12: el admin puede terminar el suyo si
 * otro cubre los días que siguen).
 *
 * La bitácora trae solo las entradas que llevan `role_assignment_id` o `department_head_id`, así que el cambio de
 * perfil no entra. Las jefaturas nombradas antes de 3.P8 llevan `department_head_id` en sus cambios, no en sus
 * propiedades, y las terminadas entonces citan sus nombramientos en `role_scope_ids`.
 */
final class PersonRecordQuery
{
    public function __construct(
        private readonly AssignmentLabels $labels,
        private readonly RoleAssignmentRules $rules,
    ) {}

    public function for(User $person): PersonRecord
    {
        $today = today()->toImmutable();
        $assignments = $person->roleAssignments()->get();
        $labels = $this->labels->describe($assignments);
        $entries = $this->entries($person);
        $endings = $this->endings($entries);

        $rows = $assignments->map(function (RoleAssignment $assignment) use ($today, $labels, $endings): array {
            $status = AssignmentStatus::of($assignment, $today);

            return [
                'assignment' => $assignment,
                'label' => $labels[$assignment->getKey()],
                'status' => $status,
                'daysLeft' => $status === AssignmentStatus::Current && $assignment->valid_to !== null
                    ? (int) $today->diffInDays($assignment->valid_to->toImmutable()->startOfDay())
                    : null,
                'endedBy' => $status === AssignmentStatus::Ended ? ($endings[$assignment->getKey()] ?? null) : null,
                'endBlocker' => $this->endBlocker($assignment),
                'earliestLastDay' => $this->rules->earliestLastDay($assignment),
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => $this->order($a, $b));

        $expiring = collect($rows)
            ->filter(fn (array $row): bool => $row['status'] === AssignmentStatus::Current && $row['assignment']->valid_to !== null)
            ->sortBy(fn (array $row): string => $row['assignment']->valid_to->toDateString())
            ->first();

        $appointed = $entries->first(fn (Activity $entry): bool => $entry->event === 'created' && $entry->properties->has('role_assignment_id'));

        return new PersonRecord(
            person: $person,
            assignments: $rows,
            current: count(array_filter($rows, fn (array $row): bool => $row['status'] === AssignmentStatus::Current)),
            past: count(array_filter($rows, fn (array $row): bool => $row['status'] === AssignmentStatus::Ended)),
            since: $assignments->isEmpty() ? null : CarbonImmutable::parse($assignments->min(fn (RoleAssignment $assignment): string => $assignment->valid_from->toDateString())),
            nextExpiry: $expiring === null ? null : ['on' => $expiring['assignment']->valid_to->toImmutable(), 'summary' => $expiring['label']->summary],
            timeline: AssignmentTimeline::build(array_map(fn (array $row): array => [
                'label' => $row['label']->summary,
                'from' => $row['assignment']->valid_from->toImmutable(),
                'to' => $row['assignment']->valid_to?->toImmutable(),
                'status' => $row['status'],
            ], $rows), $today),
            lastAppointment: $appointed === null ? null : ['on' => $this->on($appointed), 'by' => $appointed->causer?->name],
            log: $entries->take(10)->map(fn (Activity $entry): array => [
                'on' => $this->on($entry),
                'by' => $entry->causer?->name,
                'description' => (string) $entry->description,
            ])->values()->all(),
        );
    }

    /**
     * Un nombramiento de la persona por su id, para terminarlo; el de otra persona o un id que no es uuid no existe.
     *
     * @throws ModelNotFoundException<RoleAssignment>
     */
    public function assignmentOf(User $person, string $id): RoleAssignment
    {
        return KeyLookup::findOrFail($person->roleAssignments()->getQuery(), $id);
    }

    /**
     * Por qué el nombramiento no se termina desde esta pantalla; nulo si sí. Uno de Administración no se termina si
     * ningún último día desde hoy evita días sin admin vigente (AZ-S12, RoleAssignmentRules::endBlocker()).
     */
    public function endBlocker(RoleAssignment $assignment): ?string
    {
        return $this->rules->endBlocker($assignment);
    }

    /**
     * Las entradas de bitácora sobre los nombramientos de la persona, de la más reciente a la más antigua.
     *
     * @return Collection<int, Activity>
     */
    private function entries(User $person): Collection
    {
        return Activity::query()
            ->where('subject_type', $person->getMorphClass())
            ->where('subject_id', $person->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNotNull('properties->role_assignment_id')
                ->orWhereNotNull('properties->department_head_id')
                ->orWhereNotNull('attribute_changes->attributes->department_head_id'))
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Quién terminó cada nombramiento y cuándo: la entrada `updated` más reciente con su id, en `role_assignment_id`
     * o, si es de una jefatura terminada antes de 3.P8, en `role_scope_ids`.
     *
     * @param  Collection<int, Activity>  $entries
     * @return array<string, array{name: string, on: CarbonImmutable}>
     */
    private function endings(Collection $entries): array
    {
        $endings = [];

        foreach ($entries as $entry) {
            if ($entry->event !== 'updated' || $entry->causer === null) {
                continue;
            }

            $id = $entry->properties->get('role_assignment_id');
            $ids = $id !== null ? [$id] : (array) $entry->properties->get('role_scope_ids', []);

            foreach ($ids as $assignmentId) {
                $endings[$assignmentId] ??= ['name' => $entry->causer->name, 'on' => $this->on($entry)];
            }
        }

        return $endings;
    }

    private function on(Activity $entry): CarbonImmutable
    {
        return CarbonImmutable::instance($entry->created_at)->setTimezone(config('app.timezone'));
    }

    /**
     * @param  array{assignment: RoleAssignment, status: AssignmentStatus}  $a
     * @param  array{assignment: RoleAssignment, status: AssignmentStatus}  $b
     */
    private function order(array $a, array $b): int
    {
        $groups = [AssignmentStatus::Current->value => 0, AssignmentStatus::Scheduled->value => 1, AssignmentStatus::Ended->value => 2];
        $group = $groups[$a['status']->value] <=> $groups[$b['status']->value];

        if ($group !== 0) {
            return $group;
        }

        $from = $a['assignment']->valid_from->toDateString() <=> $b['assignment']->valid_from->toDateString();
        $to = ($a['assignment']->valid_to?->toDateString() ?? '') <=> ($b['assignment']->valid_to?->toDateString() ?? '');

        return match ($a['status']) {
            AssignmentStatus::Current => -$from,
            AssignmentStatus::Scheduled => $from,
            AssignmentStatus::Ended => -$to ?: -$from,
        };
    }
}
