<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\Violation;

/**
 * «Dónde cabe» un grupo en el editor de horarios (tarea 3.5): por cada día y bloque del perfil de la modalidad del
 * grupo, sin las horas donde el grupo ya tiene sesión, una sesión candidata de un bloque, sin salón, que el validador
 * revisa en un solo checkSessions() con la foto del periodo y la modalidad (leída una vez).
 *
 * Una celda cabe si la candidata no agrega violaciones duras: las que el grupo ya tenía sin ella (validateGroup()) no
 * cuentan, ni las de sus totales (HC-12, las horas semanales del grupo), que una celda sola no cumple ni rompe. Así
 * HC-08 (horas del docente) no marca celdas y HC-01, HC-03 y HC-09 sí. Un grupo que no se edita (cancelado, o con la
 * carga fuera de propuesta, asignación o ajustes; LoadEditability::blockedReasons()) y una modalidad sin bloques de
 * tiempo no tienen celdas.
 *
 * Rendimiento (200 grupos en la modalidad, 70 celdas, las 13 reglas): leer la foto cuesta unos 50 ms y las 70 celdas unos
 * 14 ms, porque ScheduleState::withSession() todavía rehace los índices de la foto por celda.
 */
final class GroupFitQuery
{
    /** Reglas sobre los totales del grupo, que no dicen si una celda cabe. */
    private const array TOTAL_RULES = ['HC-12'];

    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @return array<string, array{day: int, starts_at: string, ends_at: string, fits: bool, rules: list<string>, messages: list<string>}> por «día|HH:MM»
     */
    public function for(Group $group): array
    {
        if ($this->editability->blockedReasons([$group])[$group->id] !== null) {
            return [];
        }

        $state = $this->validator->stateFor($group->term_id, $group->modality_id);
        $profile = $state->profile($group->modality_id);

        if (! $profile->usesTimeBlocks) {
            return [];
        }

        $own = $state->sessionsOf($group->id);
        $dayStart = Minutes::of($profile->dayStart);
        $dayEnd = Minutes::of($profile->dayEnd);
        $candidates = [];

        foreach ($profile->days as $day) {
            for ($start = $dayStart; $start + $profile->blockMinutes <= $dayEnd; $start += $profile->blockMinutes) {
                $end = $start + $profile->blockMinutes;

                if (! array_any($own, fn (StateSession $session): bool => $session->day === $day && Minutes::overlap($start, $end, $session->start, $session->end))) {
                    $candidates["{$day}|".Minutes::format($start)] = new SessionCandidate($day, Minutes::format($start), Minutes::format($end));
                }
            }
        }

        $before = [];

        foreach ($this->validator->validateGroup($group)->violations() as $violation) {
            $before[$violation->key()] = true;
        }

        $cells = [];

        foreach ($this->validator->checkSessions($group, $candidates) as $key => $result) {
            $new = array_values(array_filter($result->violations(), fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard
                && ! isset($before[$violation->key()])
                && ! in_array($violation->rule, self::TOTAL_RULES, true)));
            $candidate = $candidates[$key];

            $cells[$key] = [
                'day' => $candidate->day,
                'starts_at' => $candidate->startsAt,
                'ends_at' => $candidate->endsAt,
                'fits' => $new === [],
                'rules' => array_values(array_unique(array_map(fn (Violation $violation): string => $violation->rule, $new))),
                'messages' => array_map(fn (Violation $violation): string => $violation->message, $new),
            ];
        }

        return $cells;
    }
}
