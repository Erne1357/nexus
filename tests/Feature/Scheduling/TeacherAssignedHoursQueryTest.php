<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\TeacherAssignedHoursQuery;

/*
| TeacherAssignedHoursQuery: las horas asignadas por docente que comparten HC-08 y las cargas de Staffing.
*/

function assignedHoursGroup(Term $term, Teacher $teacher, int $theory, int $practice, ?Campus $campus = null, bool $cancelled = false): Group
{
    $factory = Group::factory()->withTeacher($teacher);

    if ($cancelled) {
        $factory = $factory->cancelled();
    }

    return $factory->create([
        'term_id' => $term->id,
        'campus_id' => ($campus ?? Campus::factory()->create())->id,
        'subject_id' => Subject::factory()->create(['theory_hours' => $theory, 'practice_hours' => $practice])->id,
    ]);
}

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->teacher = Teacher::factory()->create();
});

it('suma teoría y práctica de los grupos del docente en todos los campus', function () {
    assignedHoursGroup($this->term, $this->teacher, 3, 2);
    assignedHoursGroup($this->term, $this->teacher, 4, 0);

    expect((new TeacherAssignedHoursQuery)->forTerm($this->term))->toBe([$this->teacher->id => 9]);
});

it('excluye los grupos cancelados, los de otros periodos y los sin docente', function () {
    assignedHoursGroup($this->term, $this->teacher, 3, 2);
    assignedHoursGroup($this->term, $this->teacher, 6, 0, cancelled: true);
    assignedHoursGroup(Term::factory()->create(), $this->teacher, 5, 5);
    Group::factory()->create(['term_id' => $this->term->id]);

    expect((new TeacherAssignedHoursQuery)->forTerm($this->term))->toBe([$this->teacher->id => 5]);
});

it('filtra por docentes y omite a los que no tienen grupos', function () {
    $other = Teacher::factory()->create();
    $idle = Teacher::factory()->create();
    assignedHoursGroup($this->term, $this->teacher, 3, 2);
    assignedHoursGroup($this->term, $other, 1, 1);

    $query = new TeacherAssignedHoursQuery;

    expect($query->forTerm($this->term, [$this->teacher->id, $idle->id]))->toBe([$this->teacher->id => 5])
        ->and($query->forTerm($this->term, []))->toBe([])
        ->and($query->forTerm($this->term))->toHaveCount(2);
});
