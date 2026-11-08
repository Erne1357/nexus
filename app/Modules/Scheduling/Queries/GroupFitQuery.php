<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * «Dónde cabe» un grupo en el editor de horarios (tarea 3.5): por cada día y bloque del perfil del campus, sin las
 * horas donde el grupo ya tiene sesión, una sesión candidata de un bloque, sin salón, que revisan las reglas del
 * validador.
 *
 * Una celda cabe si la candidata no agrega violaciones duras: las que el grupo ya tenía sin ella no cuentan, ni las de
 * sus totales (HC-12, las horas semanales del grupo), que una celda sola no cumple ni rompe. Un grupo que no se edita
 * (cancelado, o con la carga fuera de propuesta, asignación o ajustes; SessionRules::editBlockedReasons()) y un campus
 * sin bloques de tiempo no tienen celdas.
 *
 * Rendimiento: lee la foto del periodo y campus una vez (ScheduleValidator::stateFor()) y no la reconstruye por celda
 * (checkSessions() lo hace con la foto completa). Arma una foto reducida con lo que una sesión sin salón de este grupo
 * puede tocar: el grupo, los grupos de su docente en cualquier campus (HC-01, HC-08, HC-09) y los de su plan y semestre
 * en el campus (HC-03), con sus sesiones, materias, cupos, préstamos, docentes, salones y bloqueos, perfiles, ofertas
 * y planes activos; y corre las reglas sobre ella por celda. El grupo sin la candidata se valida sobre la misma foto
 * reducida, así que lo que la foto deja fuera (las sesiones de otros grupos en sus salones, los parámetros) sale igual
 * en las dos y no cambia la respuesta. Una regla nueva que dependa de la hora y de otros datos debe entrar aquí.
 */
final class GroupFitQuery
{
    /** Reglas sobre los totales del grupo, que no dicen si una celda cabe. */
    private const array TOTAL_RULES = ['HC-12'];

    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly SessionRules $rules,
    ) {}

    /**
     * @return array<string, array{day: int, starts_at: string, ends_at: string, fits: bool, rules: list<string>, messages: list<string>}> por «día|HH:MM»
     */
    public function for(Group $group): array
    {
        if ($this->rules->editBlockedReasons([$group])[$group->id] !== null) {
            return [];
        }

        $state = $this->validator->stateFor($group->term_id, $group->campus_id);
        $profile = $state->profile($group->campus_id);

        if (! $profile->usesTimeBlocks) {
            return [];
        }

        $local = self::neighborhood($state, $state->groupOrFail($group->id));
        $scope = ValidationScope::groups($group->id);
        $before = [];

        foreach ($this->validator->validate($local, $scope)->forGroup($group->id) as $violation) {
            $before[$violation->key()] = true;
        }

        $own = $state->sessionsOf($group->id);
        $dayStart = Minutes::of($profile->dayStart);
        $dayEnd = Minutes::of($profile->dayEnd);
        $cells = [];

        foreach ($profile->days as $day) {
            for ($start = $dayStart; $start + $profile->blockMinutes <= $dayEnd; $start += $profile->blockMinutes) {
                $end = $start + $profile->blockMinutes;

                if (array_any($own, fn (StateSession $session): bool => $session->day === $day && Minutes::overlap($start, $end, $session->start, $session->end))) {
                    continue;
                }

                $candidate = new SessionCandidate($day, Minutes::format($start), Minutes::format($end));
                $new = array_values(array_filter(
                    $this->validator->validate($local->withSession($group->id, $candidate), $scope)->forGroup($group->id),
                    fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard
                        && ! isset($before[$violation->key()])
                        && ! in_array($violation->rule, self::TOTAL_RULES, true),
                ));

                $cells["{$day}|{$candidate->startsAt}"] = [
                    'day' => $day,
                    'starts_at' => $candidate->startsAt,
                    'ends_at' => $candidate->endsAt,
                    'fits' => $new === [],
                    'rules' => array_values(array_unique(array_map(fn (Violation $violation): string => $violation->rule, $new))),
                    'messages' => array_map(fn (Violation $violation): string => $violation->message, $new),
                ];
            }
        }

        return $cells;
    }

    /**
     * La parte de la foto que una sesión sin salón del grupo puede tocar (ver el docblock de la clase).
     */
    private static function neighborhood(ScheduleState $state, StateGroup $group): ScheduleState
    {
        $groupIds = [$group->id => true];

        if ($group->teacherId !== null) {
            foreach ($state->teacherSessions($group->teacherId) as $session) {
                $groupIds[$session->groupId] = true;
            }
        }

        foreach ($state->groupsOfSemester($group->campusId, $group->studyPlanId, $group->semester) as $mate) {
            $groupIds[$mate->id] = true;
        }

        $builder = new ScheduleStateBuilder($state->termId);
        $roomIds = [];

        foreach (array_keys($groupIds) as $groupId) {
            $each = $state->groupOrFail($groupId);
            $builder->group($each)->profile($each->campusId, $state->profile($each->campusId));

            foreach ($state->sessionsOf($groupId) as $session) {
                $builder->session($session);

                if ($session->roomId !== null) {
                    $roomIds[$session->roomId] = true;
                }
            }

            foreach ($state->quotasOf($groupId) as $quota) {
                $builder->quota($quota);
            }

            foreach ($state->loansOf($groupId) as $loan) {
                $builder->loan($loan);
            }

            $subject = $state->subject($each->subjectId);
            $teacher = $each->teacherId === null ? null : $state->teacher($each->teacherId);

            if ($subject !== null) {
                $builder->subject($subject);
            }

            if ($teacher !== null) {
                $builder->teacher($teacher);
            }

            if ($state->isOffered($each->ownerCareerId, $each->campusId)) {
                $builder->offering($each->ownerCareerId, $each->campusId);
            }

            if ($state->isPlanActive($each->studyPlanId)) {
                $builder->activePlan($each->studyPlanId);
            }
        }

        foreach (array_keys($roomIds) as $roomId) {
            $room = $state->room($roomId);

            if ($room !== null) {
                $builder->room($room);
            }

            foreach ($state->blocksOf($roomId) as $block) {
                $builder->block($block);
            }
        }

        return $builder->build();
    }
}
