<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
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
use App\Modules\Scheduling\Database\Seeders\CleanScheduleDemoSeeder;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;

/*
| El horario limpio de la demo por puntos (lleva a «asignacion», #338): con grupos de dos semestres de un plan, de dos
| departamentos (uno con tres docentes, tope de 2 grupos a la vez), con prácticas de laboratorio, un cancelado y uno
| que ya tenía horario. Todo grupo activo queda con sus horas, sin choques duros, sin docente y sin salón.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create();
    $this->other = Department::factory()->create();
    $career = Career::factory()->create();
    $this->plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->campus->id]);
    User::factory()->create(['username' => 'admin']);

    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    Room::factory()->capacity(40)->count(6)->create(['building_id' => $building->id]);
    Room::factory()->capacity(40)->ofType(RoomType::Laboratorio)->count(2)->create(['building_id' => $building->id]);
    Teacher::factory()->count(3)->adscribedTo($this->department)->create();
    Teacher::factory()->count(10)->adscribedTo($this->other)->create();

    $group = fn (Department $department, int $semester, int $theory, int $practice, bool $lab = false, array $attributes = []): Group => Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => $department->id, 'theory_hours' => $theory, 'practice_hours' => $practice, 'needs_lab' => $lab])->id,
        'owner_career_id' => $career->id,
        'study_plan_id' => $this->plan->id,
        'semester' => $semester,
        'capacity' => 35,
        ...$attributes,
    ]);

    foreach ([[3, 2, true], [2, 2, false], [4, 0, false], [2, 1, false], [3, 2, false], [1, 4, true]] as [$theory, $practice, $lab]) {
        $group($this->department, 1, $theory, $practice, $lab);
        $group($this->other, 2, $theory, $practice, $lab);
    }

    $this->cancelled = $group($this->other, 3, 3, 2, false, ['status' => GroupStatus::Cancelled]);
    $this->scheduled = $group($this->other, 3, 2, 0);
    GroupSession::query()->create(['group_id' => $this->scheduled->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => SessionKind::Teoria]);
});

function seedCleanSchedule(): void
{
    app()->call([new CleanScheduleDemoSeeder, 'run'], ['campuses' => ['C2']]);
    app(ScheduleValidator::class)->forget();
}

it('da a todo grupo activo sus horas exactas, sin choques duros, sin docente y sin salón', function () {
    seedCleanSchedule();

    $groups = Group::query()->with(['subject', 'sessions'])->where('status', '!=', GroupStatus::Cancelled)->get();
    $hard = array_filter(
        app(ScheduleValidator::class)->validateLoad($this->term, modalityOf('PRES'))->violations(),
        fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
    );

    expect($hard)->toBe([]);

    foreach ($groups as $group) {
        expect($group->sessions->sum(fn (GroupSession $session): int => $session->minutes))->toBe(($group->subject->theory_hours + $group->subject->practice_hours) * 60)
            ->and($group->teacher_id)->toBeNull()
            ->and($group->sessions->whereNotNull('room_id'))->toBeEmpty();
    }
});

it('no pasa del tope de grupos simultáneos del departamento: el 70 % de sus docentes', function () {
    seedCleanSchedule();

    $load = [];

    foreach (GroupSession::query()->whereHas('group', fn ($query) => $query->where('department_id', $this->department->id))->get() as $session) {
        for ($minute = Minutes::of($session->starts_at); $minute < Minutes::of($session->ends_at); $minute += 60) {
            $load["{$session->day}|{$minute}"] = ($load["{$session->day}|{$minute}"] ?? 0) + 1;
        }
    }

    expect(max($load))->toBeLessThanOrEqual(2);
});

it('no toca los cancelados ni los que ya tenían horario, y repetirlo no duplica', function () {
    seedCleanSchedule();
    $count = GroupSession::query()->count();
    seedCleanSchedule();

    expect($this->cancelled->sessions()->count())->toBe(0)
        ->and($this->scheduled->sessions()->count())->toBe(1)
        ->and(GroupSession::query()->count())->toBe($count);
});

it('el horario sale de las claves y no de los UUID: el mismo plan con otro UUID da las mismas horas, como otra corrida de la demo', function () {
    $hours = fn (): array => GroupSession::query()->join('groups', 'groups.id', '=', 'group_sessions.group_id')->join('subjects', 'subjects.id', '=', 'groups.subject_id')
        ->where('groups.id', '!=', $this->scheduled->id)
        ->orderBy('subjects.code')->orderBy('group_sessions.day')->orderBy('group_sessions.starts_at')
        ->get(['subjects.code', 'group_sessions.day', 'group_sessions.starts_at', 'group_sessions.ends_at'])
        ->map(fn (GroupSession $session): string => "{$session->code} {$session->day} {$session->starts_at}-{$session->ends_at}")->all();
    seedCleanSchedule();
    $first = $hours();

    GroupSession::query()->where('group_id', '!=', $this->scheduled->id)->delete();
    $code = $this->plan->code;
    $this->plan->update(['code' => "{$code}-X"]);
    $copy = StudyPlan::factory()->create(['career_id' => $this->plan->career_id, 'code' => $code]);
    Group::query()->update(['study_plan_id' => $copy->id]);
    seedCleanSchedule();

    expect($first)->not->toBe([])->and($hours())->toBe($first);
});

it('se detiene con el grupo y el motivo si no cabe sin choque', function () {
    $subject = Subject::factory()->create(['department_id' => $this->other->id, 'code' => 'XYZ-9999', 'theory_hours' => 2, 'practice_hours' => 0]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'owner_career_id' => $this->plan->career_id, 'study_plan_id' => $this->plan->id, 'semester' => 4, 'capacity' => 90]);

    expect(fn () => seedCleanSchedule())->toThrow(RuntimeException::class, 'XYZ-9999');
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => seedCleanSchedule())->toThrow(RuntimeException::class, 'Los datos demo no se siembran en producción.');
});
