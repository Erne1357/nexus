<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Rules\TravelGap;
use App\Modules\Staffing\Support\SessionLabels;

/**
 * «Mi carga» (tarea 3.10): los grupos no cancelados del docente en el periodo, de todos los campus, acomodados por día
 * (lunes a viernes; el sábado solo si tiene sesiones ese día) con los huecos y los traslados entre campus de una
 * sesión a la siguiente, y la cuenta de sus horas (la misma de HC-08, TeacherHourLimit). Un traslado lleva sus minutos
 * libres y si son menos de los que pide SC-09 (TravelGap); entonces no se marca aparte el hueco. Un número constante
 * de consultas.
 */
final class MyLoadQuery
{
    public function __construct(private readonly ScheduleValidator $validator, private readonly ScheduleStateQuery $states) {}

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, subject_name: string, campus_code: string, schedule: list<array{days: string, time: string}>}>,
     *     home: ?string,
     *     travel_minutes: int,
     *     days: array<int, array{name: string, short: string, hours: int, blocks: list<array<string, mixed>>}>,
     *     hours: array{base: int, limit: int, tolerance: int, external: int, assigned: int, remaining: int, sources: list<array{source: string, hours: int}>}
     * }
     */
    public function forTeacher(Teacher $teacher, Term $term): array
    {
        $groups = Group::query()
            ->active()
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->with(['subject:id,code,name', 'campus:id,code,name', 'sessions' => fn ($sessions) => $sessions->with('room:id,code')])
            ->orderBy('code')
            ->get();

        $blocks = [];

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $blocks[$session->day][] = self::block($group, $session);
            }
        }

        $days = [];
        $travel = TravelGap::fromSettings();

        foreach (SessionLabels::DAY_NAMES as $number => $name) {
            if ($number === 6 && ! isset($blocks[6])) {
                continue;
            }

            $day = collect($blocks[$number] ?? [])->sortBy('start')->values()->all();

            foreach ($day as $index => &$block) {
                $previous = $day[$index - 1] ?? null;
                $transfer = $previous === null ? null : $travel->between($previous['campus_code'], $previous['end'], $block['campus_code'], $block['start']);
                $block['transfer'] = $transfer === null ? null : ['from' => $previous['campus_code'], ...$transfer];
                $block['gap'] = $transfer === null && $previous !== null && $block['start'] > $previous['end'] ? $block['start'] - $previous['end'] : null;
            }
            unset($block);

            $days[$number] = [
                'name' => $name,
                'short' => SessionLabels::DAYS[$number],
                'hours' => (int) round(array_sum(array_map(fn (array $block): int => $block['end'] - $block['start'], $day)) / 60),
                'blocks' => $day,
            ];
        }

        return [
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'code' => $group->code,
                'subject_name' => $group->subject->name,
                'campus_code' => $group->campus->code,
                'schedule' => SessionLabels::schedule($group->sessions),
            ])->all(),
            'days' => $days,
            'home' => $groups->countBy(fn (Group $group): string => $group->campus->code)->sortDesc()->keys()->first(),
            'travel_minutes' => $travel->minimumMinutes,
            'hours' => $this->hours($teacher, $term),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(Group $group, GroupSession $session): array
    {
        return [
            'group_id' => $group->id,
            'group_code' => $group->code,
            'subject_name' => $group->subject->name,
            'subject_code' => $group->subject->code,
            'campus_code' => $group->campus->code,
            'campus_name' => $group->campus->name,
            'room' => $session->room?->code,
            'kind' => $session->kind->value,
            'time' => SessionLabels::time($session),
            'start' => Minutes::of($session->starts_at),
            'end' => Minutes::of($session->ends_at),
        ];
    }

    /**
     * @return array{base: int, limit: int, tolerance: int, external: int, assigned: int, remaining: int, sources: list<array{source: string, hours: int}>}
     */
    private function hours(Teacher $teacher, Term $term): array
    {
        // El validador es scoped y guarda la foto que ya leyó en la petición: se olvida para leer la de ahora.
        $this->validator->forget();
        $state = $this->validator->stateFor($term->id, null);

        // La foto solo trae a los docentes con grupos activos: sin ellos, se completa para mostrar base y externas.
        if ($state->teacher($teacher->id) === null) {
            $state = $state->withTeachers($this->states->teachers($term, [$teacher->id]));
        }

        $limit = TeacherHourLimit::of($state, $teacher->id)
            ?? ['base_hours' => 0, 'external_hours' => 0, 'assigned_hours' => 0, 'tolerance' => 0, 'limit' => 0, 'remaining_hours' => 0];

        return [
            'base' => $limit['base_hours'],
            'limit' => $limit['limit'],
            'tolerance' => $limit['tolerance'],
            'external' => $limit['external_hours'],
            'assigned' => $limit['assigned_hours'],
            'remaining' => $limit['remaining_hours'],
            'sources' => TeacherExternalLoad::query()->where('term_id', $term->id)->where('teacher_id', $teacher->id)
                ->orderBy('source')->get(['source', 'hours'])
                ->map(fn (TeacherExternalLoad $load): array => ['source' => (string) $load->source, 'hours' => (int) $load->hours])->all(),
        ];
    }
}
