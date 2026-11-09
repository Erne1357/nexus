<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Modos de la demo por puntos de StaffingDemoSeeder (docs/arquitectura/testing.md, «Datos de prueba»): `start` lleva al
| punto 5 (cargas en asignación y rankings) y `staffed` al 6 (docente para todo grupo, sin choques de docente). Un mundo
| chico con un departamento de C2, grupos de 2 horas y tres docentes con horas y disponibilidad justas.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['username' => 'admin']));
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create(['code' => 'II']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id]);
    $subject = Subject::factory()->create(['department_id' => $this->department->id, 'theory_hours' => 2, 'practice_hours' => 0]);
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->campus->id]);
    $this->group = function (string $code, int $day, string $startsAt, string $endsAt, int $semester) use ($subject, $career, $plan): Group {
        $group = Group::factory()->withQuota()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id,
            'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'code' => $code, 'semester' => $semester,
        ]);
        GroupSession::factory()->for($group)->on($day, $startsAt, $endsAt)->create();

        return $group;
    };
    $this->teacher = function (string $name, int $baseHours): Teacher {
        $teacher = Teacher::factory()->create(['name' => $name]);
        TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => $this->department->id]);
        TeacherPosition::factory()->for($teacher)->asignatura($baseHours)->create();

        return $teacher;
    };
    $this->seedMode = fn (string $mode) => (new DatabaseSeeder)->setContainer(app())
        ->callWith(StaffingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'mode' => $mode]);
});

it('en modo start pasa a asignación cada carga en propuesta y rankea todos sus grupos (punto 5)', function () {
    $first = ($this->group)('A1', 1, '07:00', '09:00', 1);
    $second = ($this->group)('A2', 2, '07:00', '09:00', 1);
    ($this->teacher)('Ana', 4);

    ($this->seedMode)('start');

    expect($this->load->refresh()->status)->toBe(LoadStatus::Staffing)
        ->and(StaffingRanking::query()->where('group_id', $first->id)->exists())->toBeTrue()
        ->and(StaffingRanking::query()->where('group_id', $second->id)->exists())->toBeTrue()
        ->and(Group::query()->whereNotNull('teacher_id')->exists())->toBeFalse();
});

it('en modo start falla con el motivo del veto si Scheduling no deja pasar una carga (HC-15)', function () {
    $group = ($this->group)('A1', 1, '07:00', '09:00', 1);
    GroupSession::query()->where('group_id', $group->id)->delete();

    expect(fn () => ($this->seedMode)('start'))->toThrow(RuntimeException::class, 'La carga de II en C2 no pasa a asignación')
        ->and($this->load->refresh()->status)->toBe(LoadStatus::Proposed);
});

it('en modo staffed da docente a todo grupo sin choque, horas de más ni disponibilidad rota (HC-01, HC-08, HC-09), con su historial y su carga (punto 6)', function () {
    $groups = collect([
        ($this->group)('A1', 1, '07:00', '09:00', 1),
        ($this->group)('A2', 1, '07:00', '09:00', 3),
        ($this->group)('A3', 2, '09:00', '11:00', 5),
    ]);
    $ana = ($this->teacher)('Ana', 4);
    $beatriz = ($this->teacher)('Beatriz', 2);
    $carlos = ($this->teacher)('Carlos', 4);
    TeacherAvailability::factory()->unavailable()->create(['teacher_id' => $carlos->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);
    ($this->seedMode)('start');

    ($this->seedMode)('staffed');

    $teachers = $groups->mapWithKeys(fn (Group $group): array => [$group->code => $group->refresh()->teacher_id]);
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    expect($teachers->filter()->count())->toBe(3)
        ->and($teachers['A1'])->not->toBe($teachers['A2'])
        ->and([$teachers['A1'], $teachers['A2']])->not->toContain($carlos->id)
        ->and($teachers->filter(fn (string $id): bool => $id === $beatriz->id)->count())->toBeLessThanOrEqual(1)
        ->and(GroupTeacherAssignment::query()->whereNull('replaced_at')->count())->toBe(3)
        ->and(GroupTeacherAssignment::query()->whereNotNull('reason')->exists())->toBeFalse()
        ->and(TeacherTermLoad::query()->where('term_id', $this->term->id)->where('groups_count', '>', 0)->count())->toBe($teachers->unique()->count())
        ->and(collect([$ana, $beatriz, $carlos])->every(fn (Teacher $teacher): bool => ! $validator->validateTeacher($teacher, $this->term)->hasHard()))->toBeTrue()
        ->and(DB::table('schedule_conflicts')->where('severity', 'hard')->whereIn('rule', ['HC-01', 'HC-08', 'HC-09'])->exists())->toBeFalse();
});

it('en modo staffed deja sin docente al grupo que no tiene candidato que pase, sin fallar', function () {
    $first = ($this->group)('A1', 1, '07:00', '09:00', 1);
    $second = ($this->group)('A2', 1, '07:00', '09:00', 3);
    ($this->teacher)('Ana', 4);
    ($this->seedMode)('start');

    ($this->seedMode)('staffed');

    expect(collect([$first->refresh()->teacher_id, $second->refresh()->teacher_id])->filter())->toHaveCount(1);
});

it('rechaza un modo que no existe', function () {
    expect(fn () => ($this->seedMode)('todo'))->toThrow(RuntimeException::class, 'Modo desconocido');
});
