<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingSupplyDemoSeeder;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\StaffingServiceProvider;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
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
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->department->id]);
    $subject = $this->subject = Subject::factory()->create(['department_id' => $this->department->id, 'theory_hours' => 2, 'practice_hours' => 0]);
    $career = $this->career = Career::factory()->create(['department_id' => $this->department->id]);
    $plan = $this->plan = StudyPlan::factory()->create(['career_id' => $career->id]);
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

it('en modo staffed mueve un grupo a otro docente para que quepa uno que se quedaría sin nadie', function () {
    $first = ($this->group)('A1', 2, '09:00', '11:00', 1);
    $second = ($this->group)('A2', 1, '07:00', '09:00', 3);
    $ana = ($this->teacher)('Ana', 2);
    $beatriz = ($this->teacher)('Beatriz', 2);
    TeacherSubjectQualification::factory()->for($ana)->for($this->subject)->preferente()->create();
    TeacherAvailability::factory()->unavailable()->create(['teacher_id' => $beatriz->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);
    ($this->seedMode)('start');

    ($this->seedMode)('staffed');

    expect($second->refresh()->teacher_id)->toBe($ana->id)
        ->and($first->refresh()->teacher_id)->toBe($beatriz->id)
        ->and(GroupTeacherAssignment::query()->whereNotNull('replaced_at')->exists())->toBeFalse();
});

it('da de alta docentes de asignatura donde la capacidad no llega a 1.35 veces la demanda esperada, y al repetir ya no (demo por puntos, punto 2)', function () {
    $this->plan->subjects()->attach($this->subject->id, ['semester' => 1]);
    Student::factory()->count(70)->create(['study_plan_id' => $this->plan->id, 'career_id' => $this->career->id, 'campus_id' => $this->campus->id])
        ->each(fn (Student $student) => StudentProgress::factory()->create(['student_id' => $student->id, 'subjects_available' => [$this->subject->id]]));
    $ana = ($this->teacher)('Ana', 1);
    $supply = fn () => (new DatabaseSeeder)->setContainer(app())->callWith(StaffingSupplyDemoSeeder::class, ['campuses' => ['C2']]);

    $supply();
    $supply();

    // Las altas son los docentes que no existían: el RFC de la factory es aleatorio y puede empezar con Z, como las altas.
    $hired = Teacher::query()->whereKeyNot($ana->id)->with(['adscriptions', 'positions'])->get();

    expect($hired)->toHaveCount(1)
        ->and($hired->sole()->adscriptions->sole()->department_id)->toBe($this->department->id)
        ->and($hired->sole()->adscriptions->sole()->is_primary)->toBeTrue()
        ->and($hired->sole()->positions->sole()->base_hours)->toBe(StaffingSupplyDemoSeeder::HIRE_HOURS)
        ->and($hired->sole()->positions->sole()->valid_from->lte($this->term->starts_on))->toBeTrue();
});

it('la revisión del punto 5 compara cada carga con sus grupos en los campus de la demo: una carga sin grupos puede quedarse en borrador', function () {
    ($this->group)('A1', 1, '07:00', '09:00', 1);
    ($this->teacher)('Ana', 4);
    Campus::factory()->create(['code' => 'C1']);
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => Department::factory()->create()->id]);
    ($this->seedMode)('start');
    $check = collect(app(StaffingServiceProvider::class, ['app' => app()])->demoChecks())->firstWhere('label', 'Toda carga con grupos está en asignación');

    expect(($check->passes)(new DemoContext(DemoVolume::Massive, ['C1', 'C2'])))->toBeTrue();

    AcademicLoad::query()->whereKey($this->load->id)->update(['status' => LoadStatus::Proposed->value]);

    expect(($check->passes)(new DemoContext(DemoVolume::Massive, ['C1', 'C2'])))->toBe('1 cargas con grupos no están en asignación.');
});

it('en modo mixed no se salta la modalidad pedida porque otra modalidad ya tenga asignaciones en el campus', function () {
    ($this->group)('A1', 1, '07:00', '09:00', 1);
    ($this->teacher)('Ana', 4);
    $distance = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => modalityOf('EAD')->id]);
    GroupTeacherAssignment::factory()->create(['group_id' => $distance->id]);

    (new DatabaseSeeder)->setContainer(app())
        ->callWith(StaffingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'mode' => 'mixed', 'modality' => 'PRES']);

    expect($this->load->refresh()->status)->not->toBe(LoadStatus::Proposed);
});
