<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula el impacto de una solicitud de cambio (CR-01, tarea 8.6): simula lo que se propone con el validador sobre la
 * foto de la modalidad, sin escribir sesiones, y lo guarda en `change_requests.impact` con una línea de bitácora.
 *
 * Qué se simula (supuesto por confirmar): la propuesta completa de una vez (ScheduleValidator::checkGroupSessions), no
 * sesión por sesión. En un cambio de horario, `proposed.sessions` pasan a ser TODAS las sesiones del grupo: la
 * propuesta i toma el lugar de la sesión i de `current` (la foto al pedirla; si no hay foto, la i de las sesiones del
 * grupo por día y hora), con su salón y su tipo, y la reconoce en la sesión viva que hoy tenga ese día y esas horas;
 * si esa ya no existe, o la propuesta no tiene pareja, queda sin salón. Las sesiones vivas sin propuesta se quitan.
 * En un cambio de salón, `proposed.room_id` pasa a todas las sesiones del grupo. Una solicitud sin horario o salón
 * estructurado (solo la descripción libre) no se puede simular: el impacto lo dice con `assessable`. Solo cuenta lo
 * que el cambio agrega: se resta lo que el grupo ya tenía mal (`validateGroup` sobre el estado actual), y un mismo
 * choque cuenta una vez.
 *
 * El impacto: `hard` y `soft`, una entrada por regla con sus mensajes y los grupos, docentes y salones de los
 * choques; `affected_groups` (los grupos con los que choca, con su materia y docente), `affected_teachers` y
 * `assessed_at`. La cadena de movimientos para dejarlo sin choques la busca el motor (8.7): proposal() le da a
 * ProposeChangeAlternatives las sesiones que se proponen, y simulateSessions() revisa una alternativa con varios grupos
 * (CR-02 y CR-03) con la misma cuenta de «solo lo que agrega».
 */
final class AssessChangeImpact
{
    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * Calcula el impacto sin guardarlo.
     *
     * @return array<string, mixed>
     */
    public function simulate(ChangeRequest $request): array
    {
        $group = Group::query()->with(['sessions', 'teacher'])->findOrFail($request->group_id);
        $assessable = self::isAssessable($request);

        $impact = ['assessable' => $assessable, 'hard' => [], 'soft' => [], 'affected_groups' => [], 'affected_teachers' => []];

        if ($assessable) {
            $this->validator->forget();
            $violations = $this->violations($group, $this->candidates($request, $group));
            $impact['hard'] = $this->byRule($violations, ConflictSeverity::Hard);
            $impact['soft'] = $this->byRule($violations, ConflictSeverity::Soft);
            [$impact['affected_groups'], $impact['affected_teachers']] = $this->affected($group, $violations);
        }

        return $impact + ['assessed_at' => now()->toIso8601String()];
    }

    /**
     * Si la solicitud trae un horario o un salón que se pueda simular (CR-01).
     */
    public static function isAssessable(ChangeRequest $request): bool
    {
        return ($request->proposed['sessions'] ?? []) !== [] || isset($request->proposed['room_id']);
    }

    /**
     * Las sesiones que el grupo tendría con la solicitud, como las simula simulate() (con el salón y el tipo de la sesión
     * que cada una reemplaza); vacía si la solicitud no se puede simular.
     *
     * @return list<SessionCandidate>
     */
    public function proposal(ChangeRequest $request): array
    {
        if (! self::isAssessable($request)) {
            return [];
        }

        return $this->candidates($request, Group::query()->with('sessions')->findOrFail($request->group_id));
    }

    /**
     * El impacto de dejar varios grupos de la modalidad con exactamente estas sesiones (una alternativa del motor, CR-02 y
     * CR-03), sin escribir: solo lo que agregan (lo que esos grupos ya tenían mal no cuenta, y un mismo choque cuenta una
     * vez). Con `$fresh` lee la foto de nuevo; sin él usa la que esta instancia ya leyó (la de simulate(), para revisar
     * varias alternativas seguidas sin volver a leer la base).
     *
     * @param  array<string, list<SessionCandidate>>  $sessionsByGroup
     * @return array{hard: list<array<string, mixed>>, soft: list<array<string, mixed>>}
     */
    public function simulateSessions(string $termId, string $modalityId, array $sessionsByGroup, bool $fresh = true): array
    {
        if ($fresh) {
            $this->validator->forget();
        }

        $state = $this->validator->stateFor($termId, $modalityId);
        $scope = new ValidationScope(array_map('strval', array_keys($sessionsByGroup)));
        $existing = [];

        foreach ($this->validator->validate($state, $scope)->violations() as $violation) {
            $existing[$violation->key()] = true;
        }

        foreach ($sessionsByGroup as $groupId => $candidates) {
            $state = $state->withSessions((string) $groupId, $candidates);
        }

        $added = [];

        foreach ($this->validator->validate($state, $scope)->violations() as $violation) {
            isset($existing[$violation->key()]) || $added[$violation->key()] = $violation;
        }

        $added = array_values($added);

        return ['hard' => $this->byRule($added, ConflictSeverity::Hard), 'soft' => $this->byRule($added, ConflictSeverity::Soft)];
    }

    /**
     * Calcula el impacto, lo guarda en la solicitud y lo deja en la bitácora.
     *
     * @return array<string, mixed>
     */
    public function handle(ChangeRequest $request): array
    {
        $impact = $this->simulate($request);

        DB::transaction(function () use ($request, $impact): void {
            $request->forceFill(['impact' => $impact])->save();

            activity()
                ->performedOn($request)
                ->causedBy(auth()->user())
                ->event('impact_assessed')
                ->withProperties(['hard' => count($impact['hard']), 'soft' => count($impact['soft']), 'assessable' => $impact['assessable']])
                ->log('Calculó el impacto de la solicitud de cambio');
        });

        return $impact;
    }

    /**
     * @return list<SessionCandidate>
     */
    private function candidates(ChangeRequest $request, Group $group): array
    {
        $live = $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->values();

        if ($request->kind === ChangeRequestKind::Room) {
            $roomId = $request->proposed['room_id'] ?? null;

            return $live
                ->map(fn (GroupSession $session): SessionCandidate => new SessionCandidate($session->day, $session->starts_at, $session->ends_at, $roomId, $session->kind, $session->id))
                ->all();
        }

        $paired = $this->pairs($request, $live);
        $candidates = [];

        foreach (array_values($request->proposed['sessions'] ?? []) as $index => $proposed) {
            $current = $paired[$index] ?? null;
            $candidates[] = new SessionCandidate(
                (int) $proposed['day'],
                $proposed['starts_at'],
                $proposed['ends_at'],
                $current?->room_id,
                $current?->kind ?? SessionKind::Teoria,
                $current?->id ?? "proposed-{$index}",
            );
        }

        return $candidates;
    }

    /**
     * La sesión viva que cada propuesta reemplaza. Primero por coincidencia exacta con `current` (la foto al pedirla):
     * la propuesta con el mismo día y las mismas horas que una sesión de la foto, que se reconoce en la sesión viva que
     * hoy las tenga. Las propuestas que quedan se emparejan por posición con las sesiones vivas que quedan, en el orden
     * de la foto (sin foto, por día y hora); las de más quedan sin pareja, sin salón.
     *
     * @param  Collection<int, GroupSession>  $live
     * @return array<int, GroupSession>
     */
    private function pairs(ChangeRequest $request, Collection $live): array
    {
        $proposals = array_values($request->proposed['sessions'] ?? []);
        $snapshot = array_values($request->current ?? []);
        $clock = fn (mixed $time): string => substr((string) $time, 0, 5);
        $same = fn (GroupSession $session, array $other): bool => $session->day === (int) ($other['day'] ?? 0)
            && $clock($session->starts_at) === $clock($other['starts_at'] ?? '')
            && $clock($session->ends_at) === $clock($other['ends_at'] ?? '');

        $pairs = [];
        $taken = [];

        foreach ($proposals as $index => $proposal) {
            $match = $live->first(fn (GroupSession $session): bool => ! isset($taken[$session->id]) && $same($session, $proposal)
                && ($snapshot === [] || array_any($snapshot, fn (array $photo): bool => $same($session, $photo))));

            if ($match !== null) {
                $taken[$match->id] = true;
                $pairs[$index] = $match;
            }
        }

        $rest = $snapshot === []
            ? $live->reject(fn (GroupSession $session): bool => isset($taken[$session->id]))->values()
            : collect($snapshot)
                ->map(fn (array $photo): ?GroupSession => $live->first(fn (GroupSession $session): bool => ! isset($taken[$session->id]) && $same($session, $photo)))
                ->filter()
                ->values();

        foreach ($proposals as $index => $proposal) {
            if (! isset($pairs[$index]) && $rest->isNotEmpty()) {
                $pairs[$index] = $rest->shift();
                $taken[$pairs[$index]->id] = true;
            }
        }

        return $pairs;
    }

    /**
     * Solo lo que el cambio agrega: las violaciones de la propuesta completa menos las que el grupo ya tenía (la misma
     * regla, sujeto, mensaje y sesiones). Lo que el cambio quita no cuenta, y un mismo choque cuenta una vez.
     *
     * @param  list<SessionCandidate>  $candidates
     * @return list<Violation>
     */
    private function violations(Group $group, array $candidates): array
    {
        $existing = [];

        foreach ($this->validator->validateGroup($group)->violations() as $violation) {
            $existing[$violation->key()] = true;
        }

        $added = [];

        foreach ($this->validator->checkGroupSessions($group, $candidates)->violations() as $violation) {
            isset($existing[$violation->key()]) || $added[$violation->key()] = $violation;
        }

        return array_values($added);
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<array{rule: string, message: string, messages: list<string>, groups: list<string>, teachers: list<string>, rooms: list<string>}>
     */
    private function byRule(array $violations, ConflictSeverity $severity): array
    {
        $rules = [];

        foreach ($violations as $violation) {
            if ($violation->severity !== $severity) {
                continue;
            }

            $entry = $rules[$violation->rule] ?? ['rule' => $violation->rule, 'message' => $violation->message, 'messages' => [], 'groups' => [], 'teachers' => [], 'rooms' => []];
            $entry['messages'][] = $violation->message;

            foreach ([$violation->groupId, $violation->related['other_group_id'] ?? null] as $groupId) {
                $groupId !== null && $entry['groups'][] = $groupId;
            }

            $violation->subjectType === 'teacher' && $entry['teachers'][] = $violation->subjectId;
            $violation->subjectType === 'room' && $entry['rooms'][] = $violation->subjectId;
            $rules[$violation->rule] = $entry;
        }

        return array_values(array_map(fn (array $entry): array => [
            ...$entry,
            'messages' => array_values(array_unique($entry['messages'])),
            'groups' => array_values(array_unique($entry['groups'])),
            'teachers' => array_values(array_unique($entry['teachers'])),
            'rooms' => array_values(array_unique($entry['rooms'])),
        ], $rules));
    }

    /**
     * Los grupos con los que choca (con su materia y docente) y los docentes afectados, con nombre.
     *
     * @param  list<Violation>  $violations
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function affected(Group $group, array $violations): array
    {
        $groupIds = [];
        $teacherIds = [];

        foreach ($violations as $violation) {
            $other = $violation->related['other_group_id'] ?? null;
            $other !== null && $other !== $group->id && $groupIds[$other] = true;
            $violation->subjectType === 'teacher' && $teacherIds[$violation->subjectId] = true;
        }

        $groups = Group::query()->with(['subject:id,code,name', 'teacher:id,name'])->whereIn('id', array_keys($groupIds))->orderBy('code')->get();

        foreach ($groups as $other) {
            $other->teacher_id !== null && $teacherIds[$other->teacher_id] = true;
        }

        $affectedGroups = $groups->map(fn (Group $other): array => [
            'id' => $other->id,
            'code' => $other->code,
            'subject' => $other->subject?->name,
            'teacher_id' => $other->teacher_id,
            'teacher' => $other->teacher?->name,
        ])->values()->all();

        $affectedTeachers = Teacher::query()->whereIn('id', array_keys($teacherIds))->orderBy('name')->get(['id', 'name'])
            ->map(fn (Teacher $teacher): array => ['id' => $teacher->id, 'name' => $teacher->name])->values()->all();

        return [$affectedGroups, $affectedTeachers];
    }
}
