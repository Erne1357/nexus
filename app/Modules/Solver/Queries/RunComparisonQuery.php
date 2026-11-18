<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Rules\ApplyPlan;
use App\Modules\Solver\Support\RunPayloads;

/**
 * Arma la comparación de una corrida con la carga (RunComparison; tarea 6.10): lee su entrada y su salida
 * (RunPayloads), calcula qué escribiría aceptarla (ApplyPlan) y pone nombre a lo que el contrato trae por id (grupos,
 * docentes, salones, planes, carreras y departamentos). Una corrida sin salida (en cola, corriendo, fallida, cancelada)
 * no se compara.
 *
 * Sesión por sesión, en cada grupo que se escribe: las iguales quedan `same`; las de la misma hora con otro salón,
 * `room`; las demás se emparejan en orden de día y hora (`moved`), y lo que sobra es `new` (propuesta) o `removed` (de
 * hoy). Los grupos de la carga que se quedan son `kept` y los fijos de otras cargas, `fixed`. En la vista del
 * coordinador (`$careerId`) solo entran los grupos movibles de su carrera y los fijos.
 */
final class RunComparisonQuery
{
    public function of(SolverRun $run, ?string $careerId = null): ?RunComparison
    {
        $output = RunPayloads::output($run);

        if ($output === null) {
            return null;
        }

        $input = RunPayloads::input($run);
        $plan = ApplyPlan::of($input, $output);
        $groups = array_values(array_filter($input->groups, fn (GroupInput $group): bool => ! $group->movable || $careerId === null || $group->ownerCareerId === $careerId));

        $names = $this->names($groups, $output);
        $info = [];
        $records = [];

        foreach ($groups as $group) {
            $status = $group->movable ? $plan->status($group->id) : null;
            $info[$group->id] = $this->info($group, $names, $status, $group->movable ? ($plan->groups[$group->id]['reasons'] ?? []) : []);

            if (! $group->movable) {
                foreach ($group->fixedSessions as $index => $slot) {
                    $at = ApplyPlan::slot($slot);
                    $records[] = ['key' => "{$group->id}:f{$index}", 'group_id' => $group->id, 'state' => 'fixed', 'now' => $at, 'was' => $at];
                }

                continue;
            }

            $planned = $plan->groups[$group->id];

            if ($status !== ApplyPlan::WRITE) {
                foreach ($planned['current'] as $index => $at) {
                    $records[] = ['key' => "{$group->id}:c{$index}", 'group_id' => $group->id, 'state' => $status === ApplyPlan::KEPT ? 'kept' : 'same', 'now' => $at, 'was' => $at];
                }

                continue;
            }

            array_push($records, ...$this->diff($group->id, $planned['current'], $planned['proposed']));
        }

        return new RunComparison($info, $records, $names['rooms'], $names['teachers'], $plan);
    }

    /**
     * Las sesiones de un grupo que se escribe, de hoy contra propuestas.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $proposed
     * @return list<array{key: string, group_id: string, state: string, now: ?array<string, mixed>, was: ?array<string, mixed>}>
     */
    private function diff(string $groupId, array $current, array $proposed): array
    {
        $exact = fn (array $at): string => implode('|', [$at['day'], $at['starts_at'], $at['ends_at'], $at['kind'], $at['room_id'] ?? '']);
        $time = fn (array $at): string => implode('|', [$at['day'], $at['starts_at'], $at['ends_at'], $at['kind']]);
        $records = [];
        $n = 0;

        foreach ([$exact, $time] as $match) {
            foreach ($proposed as $p => $now) {
                foreach ($current as $c => $was) {
                    if ($match($now) === $match($was)) {
                        $records[] = ['key' => "{$groupId}:s".$n++, 'group_id' => $groupId, 'state' => $match === $exact ? 'same' : 'room', 'now' => $now, 'was' => $was];
                        unset($proposed[$p], $current[$c]);

                        continue 2;
                    }
                }
            }
        }

        $proposed = array_values($proposed);
        $current = array_values($current);

        foreach ($proposed as $index => $now) {
            $was = $current[$index] ?? null;
            $records[] = ['key' => "{$groupId}:s".$n++, 'group_id' => $groupId, 'state' => $was === null ? 'new' : 'moved', 'now' => $now, 'was' => $was];
        }

        foreach (array_slice($current, count($proposed)) as $was) {
            $records[] = ['key' => "{$groupId}:s".$n++, 'group_id' => $groupId, 'state' => 'removed', 'now' => null, 'was' => $was];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $names
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function info(GroupInput $group, array $names, ?string $status, array $reasons): array
    {
        $code = $names['groups'][$group->id]['code'] ?? '—';
        $teacher = $group->teacherId === null ? null : ($names['teachers'][$group->teacherId] ?? null);

        return [
            'id' => $group->id,
            'title' => "{$group->subjectCode} · {$code}",
            'code' => $code,
            'subject_code' => $group->subjectCode,
            'subject_name' => $names['groups'][$group->id]['subject'] ?? $group->subjectCode,
            'campus' => $group->campusCode,
            'teacher_id' => $group->teacherId,
            'teacher' => $teacher,
            'teacher_short' => $teacher ?? 'Sin docente',
            'teacher_sort' => $teacher,
            'plan_id' => $group->studyPlanId,
            'plan_code' => $names['plans'][$group->studyPlanId] ?? '—',
            'semester' => $group->semester,
            'career_id' => $group->ownerCareerId,
            'career_code' => $names['careers'][$group->ownerCareerId] ?? '—',
            'department_code' => $names['departments'][$group->departmentId] ?? '—',
            'movable' => $group->movable,
            'status' => $status,
            'reasons' => $reasons,
        ];
    }

    /**
     * Los nombres de lo que el contrato trae por id, en una consulta por catálogo.
     *
     * @param  list<GroupInput>  $groups
     * @return array{groups: array<string, array{code: string, subject: string}>, teachers: array<string, string>, rooms: array<string, array{code: string, building: string}>, plans: array<string, string>, careers: array<string, string>, departments: array<string, string>}
     */
    private function names(array $groups, SolverOutput $output): array
    {
        $ids = fn (callable $pick): array => array_values(array_unique(array_filter(array_map($pick, $groups))));
        $roomIds = [];

        foreach ($groups as $group) {
            foreach ([...$group->currentSessions, ...$group->fixedSessions] as $slot) {
                if ($slot->roomId !== null) {
                    $roomIds[$slot->roomId] = true;
                }
            }
        }

        foreach ($output->sessions as $session) {
            if ($session->roomId !== null) {
                $roomIds[$session->roomId] = true;
            }
        }

        $models = Group::query()->whereIn('id', $ids(fn (GroupInput $group): string => $group->id))->get(['id', 'code', 'subject_id']);
        $subjects = Subject::withTrashed()->whereIn('id', $models->pluck('subject_id')->unique()->all())->pluck('name', 'id');

        return [
            'groups' => $models->mapWithKeys(fn (Group $group): array => [$group->id => ['code' => $group->code, 'subject' => $subjects[$group->subject_id] ?? '']])->all(),
            'teachers' => Teacher::withTrashed()->whereIn('id', $ids(fn (GroupInput $group): ?string => $group->teacherId))->pluck('name', 'id')->all(),
            'rooms' => $this->rooms($roomIds),
            'plans' => StudyPlan::withTrashed()->whereIn('id', $ids(fn (GroupInput $group): string => $group->studyPlanId))->pluck('code', 'id')->all(),
            'careers' => Career::withTrashed()->whereIn('id', $ids(fn (GroupInput $group): string => $group->ownerCareerId))->pluck('code', 'id')->all(),
            'departments' => Department::withTrashed()->whereIn('id', $ids(fn (GroupInput $group): string => $group->departmentId))->pluck('code', 'id')->all(),
        ];
    }

    /**
     * El código y el edificio de cada salón, por id.
     *
     * @param  array<string, true>  $roomIds
     * @return array<string, array{code: string, building: string}>
     */
    private function rooms(array $roomIds): array
    {
        return Room::withTrashed()->with(['building' => fn ($building) => $building->withTrashed()])->whereIn('id', array_keys($roomIds))->get()
            ->mapWithKeys(fn (Room $room): array => [$room->id => ['code' => $room->code, 'building' => $room->building->name ?? '']])->all();
    }
}
