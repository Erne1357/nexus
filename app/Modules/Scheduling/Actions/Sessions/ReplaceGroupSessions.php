<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escribe de una vez el horario completo de varios grupos (tarea 6.10: aceptar una corrida del motor, SV-05). El editor
 * escribe una sesión a la vez y valida cada una con una foto nueva de la modalidad; para cientos de grupos eso tarda
 * minutos, así que aquí se valida y se escribe todo en una sola transacción. No sabe de puestos: quién escribe lo decide
 * quien la llama.
 *
 * Por cada grupo recibe todas sus sesiones nuevas (`SessionCandidate`, sin `replacesSessionId`) y escribe solo lo
 * diferente:
 * - antes une las que se pegan con el mismo tipo y el mismo salón, como el editor (ContiguousSessions);
 * - exige un grupo sin cancelar con su carga editable, HC-04 en cada sesión y salones del campus del grupo y activos
 *   (SessionRules), igual que el editor;
 * - conserva las sesiones que ya están igual (día, horas, tipo y salón), borra las que ya no van y crea las nuevas.
 *
 * Borra y crea con las exclusiones de `group_sessions` diferidas (HC-01, HC-02 y sin encimarse en el grupo), así dos
 * grupos pueden intercambiar horas, y las revisa todas al final: si alguna se rompe no escribe nada y lo dice con la
 * regla. Las demás reglas duras se guardan y quedan marcadas, como en el editor. Después deja los préstamos (HC-10) de
 * cada salón y día que tocó al tamaño de sus sesiones (SyncSessionLoans), una bitácora por grupo
 * (`sessions_replaced`, con las sesiones de antes y de después) y, después del commit, GroupScheduleChanged por grupo.
 * Un grupo que ya estaba igual no se toca. Devuelve, por cada grupo que cambió, cuántas sesiones quitó y cuántas creó,
 * y si pidió o no pudo pedir préstamos.
 */
final class ReplaceGroupSessions
{
    public const string EVENT = 'sessions_replaced';

    /** @var list<string> Las exclusiones de `group_sessions` (diferibles). */
    private const array EXCLUSIONS = ['group_sessions_group_no_overlap', 'group_sessions_teacher_no_overlap', 'group_sessions_room_no_overlap'];

    public function __construct(
        private readonly SessionRules $rules,
        private readonly LoadEditability $editability,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @param  array<string, list<SessionCandidate>>  $schedules  las sesiones nuevas de cada grupo, por id del grupo
     * @param  string  $origin  de dónde viene el horario, para la bitácora («de la corrida 3 del motor»)
     * @param  array<string, mixed>  $properties  lo que la bitácora de cada grupo guarda además (`solver_run_id`)
     * @return array{groups: array<string, array{removed: int, added: int}>, loans_requested: bool, loans_blocked: bool}
     *
     * @throws ValidationException
     */
    public function handle(array $schedules, User $actor, string $origin = '', array $properties = []): array
    {
        if ($schedules === []) {
            return ['groups' => [], 'loans_requested' => false, 'loans_blocked' => false];
        }

        [$result, $changed] = $this->editability->guard(fn (): array => DB::transaction(function () use ($schedules, $actor, $origin, $properties): array {
            $groups = $this->lockGroups(array_keys($schedules));
            $wanted = $this->wanted($groups, $schedules);
            $current = GroupSession::query()->whereIn('group_id', $groups->modelKeys())->orderBy('day')->orderBy('starts_at')->lockForUpdate()->get()->groupBy('group_id');

            $plans = [];

            foreach ($groups as $group) {
                $plan = $this->diff($current->get($group->id, new Collection), $wanted[$group->id]);

                if ($plan['remove'] !== [] || $plan['add'] !== []) {
                    $plans[$group->id] = $plan;
                }
            }

            $this->write($groups, $plans);

            $result = ['groups' => [], 'loans_requested' => false, 'loans_blocked' => false];
            $changed = [];

            foreach ($plans as $groupId => $plan) {
                $group = $groups[$groupId];
                $loans = $this->resyncLoans($group, $plan, $actor);
                $result['loans_requested'] = $result['loans_requested'] || $loans->requested;
                $result['loans_blocked'] = $result['loans_blocked'] || $loans->blocked;
                $result['groups'][$groupId] = ['removed' => count($plan['remove']), 'added' => count($plan['add'])];

                $before = array_map(SessionRules::snapshot(...), $current->get($groupId, new Collection)->all());
                $after = array_map(SessionRules::snapshot(...), $group->sessions()->orderBy('day')->orderBy('starts_at')->get()->all());

                activity()
                    ->causedBy($actor)
                    ->performedOn($group)
                    ->event(self::EVENT)
                    ->withChanges(['attributes' => ['sessions' => $after], 'old' => ['sessions' => $before]])
                    ->withProperties($properties)
                    ->log(trim('Escribió el horario de '.SessionRules::label($group).' '.$origin).': quitó '.count($plan['remove']).' y colocó '.count($plan['add']).' sesiones');

                $changed[] = [$group, $this->timesChanged($before, $after)];
            }

            foreach ($changed as [$group, $timesChanged]) {
                GroupScheduleChanged::dispatch($group, $timesChanged);
            }

            return [$result, $changed];
        }));

        return $result;
    }

    /**
     * Toma las cargas de los grupos en compartido y luego los grupos, en orden de id (el orden de LoadEditability), y
     * exige que cada uno se pueda editar.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Group>
     *
     * @throws ValidationException
     */
    private function lockGroups(array $ids): Collection
    {
        $groups = Group::query()->whereIn('id', $ids)->get();

        if ($groups->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['session' => 'Uno de los grupos ya no existe.']);
        }

        foreach ($groups->unique(fn (Group $group): string => "{$group->term_id}|{$group->modality_id}|{$group->department_id}") as $group) {
            $this->editability->lockLoad($group);
        }

        $locked = Group::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($locked as $group) {
            $this->rules->ensureEditable($group);
        }

        return $locked;
    }

    /**
     * Las sesiones nuevas de cada grupo en `HH:MM`, con las que se pegan unidas, revisadas con las reglas del editor.
     *
     * @param  Collection<string, Group>  $groups
     * @param  array<string, list<SessionCandidate>>  $schedules
     * @return array<string, list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>>
     *
     * @throws ValidationException
     */
    private function wanted(Collection $groups, array $schedules): array
    {
        $roomIds = [];

        foreach ($schedules as $sessions) {
            foreach ($sessions as $session) {
                if ($session->roomId !== null) {
                    $roomIds[$session->roomId] = true;
                }
            }
        }

        $rooms = Room::withTrashed()->whereIn('id', array_keys($roomIds))->get()->keyBy('id');
        $wanted = [];

        foreach ($schedules as $groupId => $sessions) {
            $group = $groups[$groupId];
            $slots = [];

            foreach ($sessions as $session) {
                $startsAt = $this->rules->time($session->startsAt);
                $endsAt = $this->rules->time($session->endsAt);
                $this->rules->ensureWithinWindow($group, $session->day, $startsAt, $endsAt);

                if ($session->roomId !== null) {
                    $room = $rooms[$session->roomId] ?? throw ValidationException::withMessages(['session' => 'Uno de los salones ya no existe.']);
                    $this->rules->ensureRoomUsable($group, $room);
                }

                $slots[] = ['day' => $session->day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'kind' => $session->kind->value, 'room_id' => $session->roomId];
            }

            $wanted[$groupId] = self::joined($slots);
        }

        return $wanted;
    }

    /**
     * Une las sesiones del mismo día que se pegan (una termina donde empieza la otra) con el mismo tipo y salón.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>  $slots
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>
     */
    public static function joined(array $slots): array
    {
        usort($slots, fn (array $a, array $b): int => [$a['day'], $a['starts_at']] <=> [$b['day'], $b['starts_at']]);
        $joined = [];

        foreach ($slots as $slot) {
            $last = array_key_last($joined);

            if ($last !== null && $joined[$last]['day'] === $slot['day'] && $joined[$last]['ends_at'] === $slot['starts_at']
                && $joined[$last]['kind'] === $slot['kind'] && $joined[$last]['room_id'] === $slot['room_id']) {
                $joined[$last]['ends_at'] = $slot['ends_at'];

                continue;
            }

            $joined[] = $slot;
        }

        return $joined;
    }

    /**
     * Lo que se borra (las sesiones que ya no van) y lo que se crea (las que no están igual).
     *
     * @param  Collection<int, GroupSession>  $current
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>  $wanted
     * @return array{remove: list<GroupSession>, add: list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>}
     */
    private function diff(Collection $current, array $wanted): array
    {
        $key = fn (array $slot): string => implode('|', [$slot['day'], $slot['starts_at'], $slot['ends_at'], $slot['kind'], $slot['room_id'] ?? '']);
        $pending = [];

        foreach ($wanted as $slot) {
            $pending[$key($slot)] = $slot;
        }

        $remove = [];

        foreach ($current as $session) {
            $existing = $key(SessionRules::snapshot($session));

            if (isset($pending[$existing])) {
                unset($pending[$existing]);

                continue;
            }

            $remove[] = $session;
        }

        return ['remove' => $remove, 'add' => array_values($pending)];
    }

    /**
     * Borra y crea con las exclusiones diferidas y las revisa al final.
     *
     * @param  Collection<string, Group>  $groups
     * @param  array<string, array{remove: list<GroupSession>, add: list<array<string, mixed>>}>  $plans
     *
     * @throws ValidationException
     */
    private function write(Collection $groups, array $plans): void
    {
        if ($plans === []) {
            return;
        }

        try {
            DB::transaction(function () use ($groups, $plans): void {
                DB::statement('set constraints '.implode(', ', self::EXCLUSIONS).' deferred');

                $remove = array_merge(...array_map(fn (array $plan): array => array_map(fn (GroupSession $session): string => $session->id, $plan['remove']), array_values($plans)));

                if ($remove !== []) {
                    GroupSession::query()->whereIn('id', $remove)->delete();
                }

                foreach ($plans as $groupId => $plan) {
                    foreach ($plan['add'] as $slot) {
                        $groups[$groupId]->sessions()->create([...$slot, 'kind' => SessionKind::from($slot['kind'])]);
                    }
                }

                DB::statement('set constraints '.implode(', ', self::EXCLUSIONS).' immediate');
            });
        } catch (QueryException $exception) {
            throw $this->overlap($exception);
        }
    }

    /**
     * El error de una exclusión, con la regla y el día; cualquier otro error de la base sigue su camino.
     *
     * @throws QueryException
     */
    private function overlap(QueryException $exception): ValidationException
    {
        $message = $exception->getMessage();
        $day = preg_match('/=\((?:[^,]+, ){1,2}(\d),/', $message, $match) === 1 ? ' el '.Minutes::dayName((int) $match[1]) : '';

        $text = match (true) {
            str_contains($message, 'group_sessions_teacher_no_overlap') => "HC-01: un docente quedaría con dos clases a la vez{$day}.",
            str_contains($message, 'group_sessions_room_no_overlap') => "HC-02: un salón quedaría ocupado dos veces a la misma hora{$day}.",
            str_contains($message, 'group_sessions_group_no_overlap') => "Un grupo quedaría con dos sesiones encimadas{$day}.",
            default => throw $exception,
        };

        return ValidationException::withMessages(['session' => "{$text} No se escribió nada."]);
    }

    /**
     * Los préstamos de cada salón y día que tocó el grupo (antes o después), al tamaño de sus sesiones.
     *
     * @param  array{remove: list<GroupSession>, add: list<array{day: int, room_id: ?string}>}  $plan
     */
    private function resyncLoans(Group $group, array $plan, User $actor): LoanSyncResult
    {
        $touched = [];

        foreach ($plan['remove'] as $session) {
            if ($session->room_id !== null) {
                $touched["{$session->room_id}|{$session->day}"] = [$session->room_id, $session->day];
            }
        }

        foreach ($plan['add'] as $slot) {
            if ($slot['room_id'] !== null) {
                $touched["{$slot['room_id']}|{$slot['day']}"] = [$slot['room_id'], $slot['day']];
            }
        }

        ksort($touched);
        $result = new LoanSyncResult;

        foreach ($touched as [$roomId, $day]) {
            $result = $result->and($this->loans->resync($group, Room::withTrashed()->findOrFail($roomId), $day, $actor));
        }

        return $result;
    }

    /**
     * Si cambiaron días u horas (no solo salones): la clave del grupo y el ranking siguen a las horas.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string}>  $before
     * @param  list<array{day: int, starts_at: string, ends_at: string}>  $after
     */
    private function timesChanged(array $before, array $after): bool
    {
        $times = function (array $sessions): array {
            $keys = array_map(fn (array $session): string => "{$session['day']}|{$session['starts_at']}|{$session['ends_at']}", $sessions);
            sort($keys);

            return $keys;
        };

        return $times($before) !== $times($after);
    }
}
