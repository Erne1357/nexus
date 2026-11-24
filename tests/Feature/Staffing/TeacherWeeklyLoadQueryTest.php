<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\NoTeacherWeeklyLoad;
use App\Modules\Faculty\Queries\TeacherWeeklyLoad;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Queries\TeacherWeeklyLoadQuery;
use Carbon\CarbonImmutable;

/*
| La carga semanal que muestra la ficha del docente (D-03): periodo en curso por fechas y siguiente periodo con grupos
| de Nexus. Faculty trae una implementación vacía y Staffing la reemplaza.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'America/Ciudad_Juarez'));
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->next = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->later = Term::factory()->create(['code' => '20273', 'name' => 'Ago-Dic 2027', 'starts_on' => '2027-08-16', 'ends_on' => '2027-12-10']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->dept = Department::factory()->create(['code' => 'SC']);
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create(['rfc' => 'AAAA800101AA1']);
    TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->dept->id, 'theory_hours' => 4, 'practice_hours' => 0]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->for($this->campus)->create()->id, 'code' => '705']);
});

function weeklyLoadGroup(Term $term, Teacher $teacher): void
{
    $group = Group::factory()->create(['term_id' => $term->id, 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id, 'code' => 'C', 'teacher_id' => $teacher->id]);
    GroupSession::factory()->on(2, '09:00', '11:00')->create(['group_id' => $group->id, 'room_id' => test()->room->id]);
}

it('Staffing reemplaza la carga vacía de Faculty', function () {
    expect(app(TeacherWeeklyLoad::class))->toBeInstanceOf(TeacherWeeklyLoadQuery::class);
    expect((new NoTeacherWeeklyLoad)->forTeacher($this->teacher))->toMatchArray(['current' => null, 'next' => null]);
});

it('el periodo en curso es el que contiene hoy y el siguiente solo existe con grupos de Nexus', function () {
    $result = app(TeacherWeeklyLoad::class)->forTeacher($this->teacher);

    expect($result['current']['term'])->toBe('Ago-Dic 2026')
        ->and($result['next'])->toBeNull()
        ->and($result['upcoming'])->toBe('Ene-Jun 2027')
        ->and($result['base_hours'])->toBe(40);

    weeklyLoadGroup($this->later, $this->teacher);
    $result = app(TeacherWeeklyLoad::class)->forTeacher($this->teacher);

    expect($result['next']['term'])->toBe('Ago-Dic 2027')
        ->and($result['next']['source'])->toBe('nexus')
        ->and($result['next']['hours'])->toBe(4)
        ->and($result['next']['days'][2]['blocks'][0]['room'])->toBe('705');
});
