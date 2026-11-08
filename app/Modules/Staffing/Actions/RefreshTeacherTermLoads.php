<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula `teacher_term_loads` de un periodo (tarea 3.12): horas base, externas, asignadas, tope y restantes de
 * cada docente activo y no borrado con al menos una adscripción vigente, o solo de los que se piden. La cuenta es la
 * de HC-08 (TeacherHourLimit) sobre una sola foto del periodo con todos los campus; los docentes sin grupos no
 * vienen en la foto y se completan con una consulta (`ScheduleStateQuery::teachers`), así salen con su base y
 * asignadas en cero. Escribe con un upsert por bloque en una transacción y borra la fila de quien ya no califica.
 *
 * Es una tabla derivada: no deja bitácora ni avisa. La corre el job RefreshTeacherLoads.
 */
final class RefreshTeacherTermLoads
{
    private const int CHUNK = 500;

    public function __construct(private readonly ScheduleStateQuery $states) {}

    /**
     * @param  list<string>|null  $teacherIds  Solo estos docentes; null, todos.
     * @return int Las filas escritas.
     */
    public function handle(Term $term, ?array $teacherIds = null): int
    {
        $qualifying = Teacher::query()
            ->where('is_active', true)
            ->whereHas('adscriptions')
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('id', $teacherIds))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $state = $this->states->forCampus($term);
        $missing = array_values(array_filter($qualifying, fn (string $id): bool => $state->teacher($id) === null));

        if ($missing !== []) {
            $state = $state->withTeachers($this->states->teachers($term, $missing));
        }

        $now = now();
        $rows = [];

        foreach ($qualifying as $id) {
            $hours = TeacherHourLimit::of($state, $id);

            if ($hours === null) {
                continue;
            }

            $rows[] = [
                'teacher_id' => $id,
                'term_id' => $term->getKey(),
                'base_hours' => $hours['base_hours'],
                'external_hours' => $hours['external_hours'],
                'assigned_hours' => $hours['assigned_hours'],
                'limit_hours' => $hours['limit'],
                'remaining_hours' => $hours['remaining_hours'],
                'groups_count' => count($hours['groups']),
                'computed_at' => $now,
            ];
        }

        DB::transaction(function () use ($term, $teacherIds, $rows): void {
            TeacherTermLoad::query()
                ->where('term_id', $term->getKey())
                ->when($teacherIds !== null, fn ($query) => $query->whereIn('teacher_id', $teacherIds))
                ->whereNotIn('teacher_id', array_column($rows, 'teacher_id'))
                ->delete();

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                TeacherTermLoad::query()->upsert($chunk, ['teacher_id', 'term_id'],
                    ['base_hours', 'external_hours', 'assigned_hours', 'limit_hours', 'remaining_hours', 'groups_count', 'computed_at']);
            }
        });

        return count($rows);
    }
}
