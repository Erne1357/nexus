<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
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

it('parte la semana por departamento: adscripción primero, los demás con su jefe y horas (1.59)', function () {
    $other = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $other->id])->create(['name' => 'Héctor Olivas']);
    $mine = Group::factory()->create(['term_id' => $this->next->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['department_id' => $this->dept->id, 'theory_hours' => 2, 'practice_hours' => 0])->id, 'department_id' => $this->dept->id, 'code' => 'C', 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->on(2, '09:00', '11:00')->create(['group_id' => $mine->id, 'room_id' => $this->room->id]);
    $subject = Subject::factory()->create(['department_id' => $other->id, 'theory_hours' => 3, 'practice_hours' => 0]);
    $theirs = Group::factory()->create(['term_id' => $this->next->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'department_id' => $other->id, 'code' => 'A', 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->on(3, '09:00', '12:00')->create(['group_id' => $theirs->id, 'room_id' => $this->room->id]);

    $week = app(TeacherWeeklyLoad::class)->forTeacher($this->teacher)['next'];

    expect($week['hours'])->toBe(5)
        ->and(array_column($week['departments'], 'name'))->toBe([$this->dept->name, 'Ciencias Básicas'])
        ->and($week['departments'][0])->toMatchArray(['home' => true, 'hours' => 2, 'head' => null])
        ->and($week['departments'][1])->toMatchArray(['home' => false, 'hours' => 3, 'head' => 'Héctor Olivas'])
        ->and($week['departments'][1]['groups'])->toHaveCount(1)
        ->and($week['days'][2]['blocks'][0]['tone'])->toBe($week['departments'][0]['tone'])
        ->and($week['days'][3]['blocks'][0]['tone'])->toBe($week['departments'][1]['tone'])
        ->and($week['departments'][0]['tone'])->not->toBe($week['departments'][1]['tone']);
});

it('la semana del SII no se parte por departamento', function () {
    expect(app(TeacherWeeklyLoad::class)->forTeacher($this->teacher)['current']['departments'])->toBe([]);
});

it('1.59: un grupo sin sesiones de otro departamento aparece en su sección y las horas son las de la materia, como en el encabezado', function () {
    $other = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $subject = Subject::factory()->create(['department_id' => $other->id, 'theory_hours' => 3, 'practice_hours' => 2]);
    $theirs = Group::factory()->create(['term_id' => $this->next->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'department_id' => $other->id, 'code' => 'A', 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->on(3, '09:00', '10:00')->create(['group_id' => $theirs->id, 'room_id' => $this->room->id]);
    $bare = Group::factory()->create(['term_id' => $this->next->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'department_id' => $other->id, 'code' => 'B', 'teacher_id' => $this->teacher->id]);

    $week = app(TeacherWeeklyLoad::class)->forTeacher($this->teacher)['next'];
    $section = collect($week['departments'])->firstWhere('name', 'Ciencias Básicas');

    expect(array_column($section['groups'], 'code'))->toEqualCanonicalizing(['A', 'B'])
        ->and($section['hours'])->toBe(10)
        ->and(array_sum(array_column($week['departments'], 'hours')))->toBe($week['hours'])
        ->and($bare->sessions()->count())->toBe(0);
});
