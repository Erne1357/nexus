<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Facades\DB;

/**
 * Calcula el impacto de una solicitud de cambio (CR-01, tarea 8.6): simula lo que se propone con el validador sobre la
 * foto de la modalidad, sin escribir sesiones, y lo guarda en `change_requests.impact` con una línea de bitácora.
 *
 * Qué se simula (supuesto por confirmar): en un cambio de horario, `proposed.sessions` reemplazan las sesiones del grupo
 * en orden (la primera propuesta toma el lugar de la primera sesión, con su salón y su tipo; las que sobran se
 * agregan sin salón); en un cambio de salón, `proposed.room_id` pasa a todas las sesiones del grupo. Una solicitud sin
 * horario o salón estructurado (solo la descripción libre) no se puede simular: el impacto lo dice con `assessable`.
 *
 * El impacto: `hard` y `soft`, una entrada por regla con sus mensajes y los grupos, docentes y salones de los
 * choques; `affected_groups` (los grupos con los que choca, con su materia y docente), `affected_teachers` y
 * `assessed_at`. La cadena de movimientos para dejarlo sin choques queda para la búsqueda del motor (8.7).
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
        $candidates = $this->candidates($request, $group);

        $impact = ['assessable' => $candidates !== [], 'hard' => [], 'soft' => [], 'affected_groups' => [], 'affected_teachers' => []];

        if ($candidates !== []) {
            $this->validator->forget();
            $violations = $this->violations($group, $candidates);
            $impact['hard'] = $this->byRule($violations, ConflictSeverity::Hard);
            $impact['soft'] = $this->byRule($violations, ConflictSeverity::Soft);
            [$impact['affected_groups'], $impact['affected_teachers']] = $this->affected($group, $violations);
        }

        return $impact + ['assessed_at' => now()->toIso8601String()];
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
     * Si el impacto guardado ya no es de fiar: no hay, o el horario de la modalidad cambió después de calcularlo.
     */
    public function isStale(ChangeRequest $request): bool
    {
        $assessedAt = $request->impact['assessed_at'] ?? null;

        if ($assessedAt === null) {
            return true;
        }

        $changedAt = DB::table('group_sessions')
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->where('groups.term_id', $request->term_id)
            ->where('groups.modality_id', $request->modality_id)
            ->max('group_sessions.updated_at');

        return $changedAt !== null && now()->parse($changedAt)->greaterThan(now()->parse($assessedAt));
    }

    /**
     * @return list<SessionCandidate>
     */
    private function candidates(ChangeRequest $request, Group $group): array
    {
        $sessions = $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->values();

        if ($request->kind === ChangeRequestKind::Room) {
            $roomId = $request->proposed['room_id'] ?? null;

            return $roomId === null ? [] : $sessions
                ->map(fn (GroupSession $session): SessionCandidate => new SessionCandidate($session->day, $session->starts_at, $session->ends_at, $roomId, $session->kind, $session->id))
                ->all();
        }

        $candidates = [];

        foreach (array_values($request->proposed['sessions'] ?? []) as $index => $proposed) {
            $current = $sessions->get($index);
            $candidates[] = new SessionCandidate(
                (int) $proposed['day'],
                $proposed['starts_at'],
                $proposed['ends_at'],
                $current?->room_id,
                $current?->kind ?? SessionKind::Teoria,
                $current?->id,
            );
        }

        return $candidates;
    }

    /**
     * Solo lo que el cambio provoca: lo que el grupo ya tenía mal antes de proponerlo no es impacto.
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

        $unique = [];

        foreach ($this->validator->checkSessions($group, $candidates) as $result) {
            foreach ($result->violations() as $violation) {
                isset($existing[$violation->key()]) || $unique[$violation->key()] = $violation;
            }
        }

        return array_values($unique);
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
