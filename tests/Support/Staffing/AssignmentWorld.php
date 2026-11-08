<?php

namespace Tests\Support\Staffing;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Carbon\CarbonInterface;

/**
 * Un departamento con su carga en un campus y un periodo, para las pruebas de la asignación docente: materias,
 * carreras, grupos con sesiones, docentes con plazas y el ranking de cada grupo. Nombres y claves ficticios.
 */
final class AssignmentWorld
{
    private function __construct(
        public readonly Campus $campus,
        public readonly Term $term,
        public readonly Department $department,
        public readonly AcademicLoad $load,
    ) {}

    public static function create(LoadStatus $status = LoadStatus::Staffing): self
    {
        $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
        $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
        $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
        $load = AcademicLoad::factory()->inStatus($status)->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'department_id' => $department->id]);

        return new self($campus, $term, $department, $load);
    }

    public function subject(string $code, string $name, ?Department $department = null): Subject
    {
        return Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => ($department ?? $this->department)->id]);
    }

    public function career(string $code, string $name = 'Ingeniería'): Career
    {
        return Career::factory()->create(['code' => $code, 'name' => "{$name} {$code}"]);
    }

    /**
     * Un grupo de la materia en el periodo y campus del mundo, con sus sesiones: [día, inicio, fin].
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $sessions
     * @param  array<string, mixed>  $attributes
     */
    public function group(Subject $subject, Career $career, string $code, int $semester = 1, array $sessions = [[1, '07:00', '08:00']], array $attributes = []): Group
    {
        $group = Group::factory()->create([
            'term_id' => $this->term->id,
            'campus_id' => $this->campus->id,
            'subject_id' => $subject->id,
            'owner_career_id' => $career->id,
            'semester' => $semester,
            'code' => $code,
            ...$attributes,
        ]);

        foreach ($sessions as [$day, $startsAt, $endsAt]) {
            GroupSession::factory()->for($group)->on($day, $startsAt, $endsAt)->create();
        }

        return $group;
    }

    /**
     * Un docente activo con sus plazas vigentes: 'tc', 'mt', 'hon' o un número (horas de asignatura).
     *
     * @param  list<string|int>  $positions
     */
    public function teacher(string $name, array $positions = ['tc']): Teacher
    {
        $teacher = Teacher::factory()->adscribedTo($this->department)->create(['name' => $name]);

        foreach ($positions as $position) {
            $factory = TeacherPosition::factory()->for($teacher);
            $factory = match ($position) {
                'tc' => $factory->tiempoCompleto(),
                'mt' => $factory->medioTiempo(),
                'hon' => $factory->honorarios(),
                default => $factory->asignatura((int) $position),
            };
            $factory->create();
        }

        return $teacher;
    }

    /**
     * El ranking del grupo: un renglón por docente, en ese orden, con su desglose (lo que no se da, el de omisión).
     *
     * @param  list<Teacher>  $teachers
     * @param  array<int, array<string, mixed>>  $breakdowns  por índice del docente
     */
    public function rank(Group $group, array $teachers, array $breakdowns = [], ?CarbonInterface $computedAt = null): void
    {
        foreach (array_values($teachers) as $index => $teacher) {
            StaffingRanking::factory()->create([
                'group_id' => $group->id,
                'teacher_id' => $teacher->id,
                'rank' => $index + 1,
                'score' => 20 - $index,
                'breakdown' => [
                    'base_hours' => 40,
                    'history_count' => 3,
                    'qualification' => 'preferente',
                    'evaluation' => 4.5,
                    'remaining_hours' => 12,
                    'flags' => [],
                    'from_other_department' => false,
                    ...($breakdowns[$index] ?? []),
                ],
                'computed_at' => $computedAt ?? now(),
            ]);
        }
    }

    /**
     * Deja al docente asignado al grupo, como lo dejaría AssignTeacher.
     */
    public function assign(Group $group, Teacher $teacher, User $by, int $position = 1, int $size = 3, ?string $reason = null): GroupTeacherAssignment
    {
        $group->update(['teacher_id' => $teacher->id]);

        return GroupTeacherAssignment::factory()->create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'assigned_by' => $by->id,
            'ranking_position_at_assignment' => $position,
            'ranking_size_at_assignment' => $size,
            'reason' => $reason,
        ]);
    }
}
