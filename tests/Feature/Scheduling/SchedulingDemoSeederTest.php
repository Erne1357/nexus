<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Database\Seeders\SchedulingDemoSeeder;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;

/*
| Horarios, docentes y salones de prueba (tarea 3.4): un campus con doce grupos de un mismo plan y semestre, cuatro
| docentes del departamento y salones de varias capacidades; las proporciones se fuerzan para que cada caso aparezca.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create();
    $this->career = Career::factory()->create();
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    User::factory()->create(['username' => 'admin']);

    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    Room::factory()->capacity(20)->create(['building_id' => $building->id]);
    Room::factory()->capacity(45)->count(2)->create(['building_id' => $building->id]);
    Room::factory()->capacity(30)->ofType(RoomType::Laboratorio)->create(['building_id' => $building->id]);
    Teacher::factory()->count(4)->adscribedTo($this->department)->create();

    $hours = [[3, 2], [2, 2], [2, 1], [3, 0], [2, 2], [3, 2], [4, 0], [1, 1], [2, 1], [3, 2], [2, 2], [3, 0]];

    foreach ($hours as $index => [$theory, $practice]) {
        $subject = Subject::factory()->create([
            'department_id' => $this->department->id,
            'theory_hours' => $theory,
            'practice_hours' => $practice,
            'needs_lab' => $index === 0 || $index === 5,
        ]);

        Group::factory()->create([
            'term_id' => $this->term->id,
            'campus_id' => $this->campus->id,
            'subject_id' => $subject->id,
            'owner_career_id' => $this->career->id,
            'study_plan_id' => $this->plan->id,
            'semester' => 1,
            'capacity' => 35,
        ]);
    }
});

function seedScheduling(): void
{
    app()->call([new SchedulingDemoSeeder, 'run'], [
        'profile' => 'demo',
        'campuses' => ['C2'],
        'ratios' => ['overlap_pairs' => 1, 'small_room_sessions' => 0.3, 'lab_in_aula_sessions' => 0.5, 'room_sessions' => 0.9],
    ]);
}

it('da horario a los grupos con las horas de su materia, salvo los que deben quedar mal', function () {
    seedScheduling();

    $mismatched = ScheduleConflict::query()->where('rule', 'HC-12')->pluck('group_id')->all();
    $withSessions = Group::query()->with(['subject', 'sessions'])->has('sessions')->get();

    expect($withSessions)->not->toBeEmpty()
        ->and($mismatched)->not->toBeEmpty();

    foreach ($withSessions->reject(fn (Group $group): bool => in_array($group->id, $mismatched, true)) as $group) {
        $minutes = $group->sessions->sum(fn (GroupSession $session): int => $session->minutes);

        expect($minutes)->toBe(($group->subject->theory_hours + $group->subject->practice_hours) * 60);
    }
});

it('deja variedad: sin horario, sin docente, cancelados, confirmados y salones', function () {
    seedScheduling();

    expect(Group::query()->doesntHave('sessions')->count())->toBeGreaterThan(0)
        ->and(Group::query()->whereNull('teacher_id')->count())->toBeGreaterThan(0)
        ->and(Group::query()->whereNotNull('teacher_id')->count())->toBeGreaterThan(0)
        ->and(Group::query()->where('status', GroupStatus::Cancelled)->count())->toBeGreaterThan(0)
        ->and(Group::query()->where('status', GroupStatus::Confirmed)->count())->toBeGreaterThan(0)
        ->and(GroupSession::query()->whereNotNull('room_id')->count())->toBeGreaterThan(0)
        ->and(GroupSession::query()->whereNull('room_id')->count())->toBeGreaterThan(0);
});

it('deja choques guardados: empalme del alumno tipo, salón chico y práctica en aula (HC-03, HC-05, HC-06)', function () {
    seedScheduling();

    expect(ScheduleConflict::query()->open()->count())->toBeGreaterThan(0)
        ->and(ScheduleConflict::query()->where('rule', 'HC-03')->exists())->toBeTrue()
        ->and(ScheduleConflict::query()->where('rule', 'HC-05')->exists())->toBeTrue()
        ->and(ScheduleConflict::query()->where('rule', 'HC-06')->exists())->toBeTrue();
});

it('no le da docente a un grupo cancelado ni a uno que la proporción deja sin él', function () {
    seedScheduling();

    expect(Group::query()->where('status', GroupStatus::Cancelled)->whereNotNull('teacher_id')->count())->toBe(0);
});

it('es idempotente: volver a correrlo no duplica sesiones ni cambia docentes', function () {
    seedScheduling();
    $sessions = GroupSession::query()->count();
    $teachers = Group::query()->orderBy('id')->pluck('teacher_id', 'id')->all();

    seedScheduling();

    expect(GroupSession::query()->count())->toBe($sessions)
        ->and(Group::query()->orderBy('id')->pluck('teacher_id', 'id')->all())->toBe($teachers);
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => seedScheduling())->toThrow(RuntimeException::class);
});
