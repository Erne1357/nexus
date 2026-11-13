<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Queue;

/*
| RefreshTeacherTermLoads, el job RefreshTeacherLoads y sus disparadores (tarea 3.12): `teacher_term_loads` con la
| cuenta de HC-08 sobre todos los campus.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->department = Department::factory()->create();
    $this->c1 = Campus::factory()->create();
    $this->c2 = Campus::factory()->create();
});

function loadTeacher(Department $department, ?string $kind = 'tiempoCompleto'): Teacher
{
    $teacher = Teacher::factory()->adscribedTo($department)->create();

    if ($kind !== null) {
        TeacherPosition::factory()->for($teacher)->{$kind}()->create();
    }

    return $teacher;
}

function teach(Teacher $teacher, Term $term, Campus $campus, int $hours, array $overrides = []): Group
{
    return Group::factory()->create([
        'term_id' => $term->id,
        'campus_id' => $campus->id,
        'teacher_id' => $teacher->id,
        'subject_id' => Subject::factory()->create(['theory_hours' => $hours, 'practice_hours' => 0])->id,
        ...$overrides,
    ]);
}

function loadOf(Teacher $teacher, Term $term): ?TeacherTermLoad
{
    return TeacherTermLoad::query()->where('teacher_id', $teacher->id)->where('term_id', $term->id)->first();
}

describe('RefreshTeacherTermLoads', function () {
    it('HC-08: suma las horas de todos los campus y calcula el tope y lo que resta', function () {
        $teacher = loadTeacher($this->department);
        teach($teacher, $this->term, $this->c1, 5);
        teach($teacher, $this->term, $this->c2, 10);
        TeacherExternalLoad::factory()->for($teacher)->create(['term_id' => $this->term->id, 'hours' => 6]);
        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.extra_hours_tolerance', 'value' => 3]);

        expect(app(RefreshTeacherTermLoads::class)->handle($this->term))->toBe(1);

        expect(loadOf($teacher, $this->term))
            ->base_hours->toBe(40)->external_hours->toBe(6)->assigned_hours->toBe(15)
            ->limit_hours->toBe(43)->remaining_hours->toBe(22)->groups_count->toBe(2);
    });

    it('usa la tolerancia configurada para el tope', function () {
        $teacher = loadTeacher($this->department, 'medioTiempo');
        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.extra_hours_tolerance', 'value' => 5]);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($teacher, $this->term))->limit_hours->toBe(25)->remaining_hours->toBe(25);
    });

    it('deja el restante negativo cuando rebasa su tope', function () {
        $teacher = loadTeacher($this->department, 'medioTiempo');
        teach($teacher, $this->term, $this->c2, 18);
        teach($teacher, $this->term, $this->c1, 8);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($teacher, $this->term))->assigned_hours->toBe(26)->limit_hours->toBe(20)->remaining_hours->toBe(-6);
    });

    it('topa a los de honorarios con el máximo configurado', function () {
        $teacher = loadTeacher($this->department, 'honorarios');
        teach($teacher, $this->term, $this->c2, 4);
        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.honorarios_max_hours', 'value' => 12]);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($teacher, $this->term))->limit_hours->toBe(12)->remaining_hours->toBe(8);
    });

    it('incluye al docente sin grupos con su base y las asignadas en cero', function () {
        $teacher = loadTeacher($this->department);
        teach(loadTeacher($this->department, 'medioTiempo'), $this->term, $this->c2, 4);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($teacher, $this->term))->base_hours->toBe(40)->assigned_hours->toBe(0)->groups_count->toBe(0)->remaining_hours->toBe(40);
    });

    it('no cuenta los grupos cancelados ni los de otro periodo', function () {
        $teacher = loadTeacher($this->department);
        teach($teacher, $this->term, $this->c2, 5, ['status' => 'cancelled']);
        teach($teacher, Term::factory()->create(), $this->c2, 7);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($teacher, $this->term))->assigned_hours->toBe(0);
    });

    it('solo calcula a los docentes activos con una adscripción', function () {
        $active = loadTeacher($this->department);
        $inactive = Teacher::factory()->inactive()->adscribedTo($this->department)->create();
        $orphan = Teacher::factory()->create();
        $removed = loadTeacher($this->department);
        TeacherDepartment::query()->where('teacher_id', $removed->id)->delete();

        $count = app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect($count)->toBe(1)
            ->and(loadOf($active, $this->term))->not->toBeNull()
            ->and(loadOf($inactive, $this->term))->toBeNull()
            ->and(loadOf($orphan, $this->term))->toBeNull()
            ->and(loadOf($removed, $this->term))->toBeNull();
    });

    it('calcula solo a los docentes pedidos', function () {
        $first = loadTeacher($this->department);
        $second = loadTeacher($this->department);

        app(RefreshTeacherTermLoads::class)->handle($this->term, [$first->id]);

        expect(loadOf($first, $this->term))->not->toBeNull()->and(loadOf($second, $this->term))->toBeNull();
    });

    it('quita la fila del docente que ya no califica y reescribe la de los demás', function () {
        $stays = loadTeacher($this->department);
        $leaves = loadTeacher($this->department);
        app(RefreshTeacherTermLoads::class)->handle($this->term);
        $leaves->update(['is_active' => false]);
        teach($stays, $this->term, $this->c2, 5);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(loadOf($leaves, $this->term))->toBeNull()
            ->and(loadOf($stays, $this->term))->assigned_hours->toBe(5)
            ->and(TeacherTermLoad::query()->where('term_id', $this->term->id)->count())->toBe(1);
    });

    it('no toca las filas de otro periodo', function () {
        $teacher = loadTeacher($this->department);
        $other = TeacherTermLoad::factory()->create(['teacher_id' => $teacher->id]);

        app(RefreshTeacherTermLoads::class)->handle($this->term);

        expect(TeacherTermLoad::query()->find($other->id))->not->toBeNull();
    });
});

describe('disparadores', function () {
    beforeEach(function () {
        Queue::fake();
        $this->teacher = loadTeacher($this->department);
        $this->group = teach($this->teacher, $this->term, $this->c2, 5);
        $this->proposed = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $this->term->id, 'modality_id' => $this->c2->modality_id, 'department_id' => $this->department->id]);
    });

    it('al cambiar el docente de un grupo, al cancelarlo o al crear grupos con docente encola el periodo', function () {
        foreach ([
            fn () => GroupTeacherChanged::dispatch($this->group, null, $this->teacher->id),
            fn () => GroupCancelled::dispatch($this->group),
            fn () => GroupsCreated::dispatch($this->term->id, $this->c2->id, [$this->group->id]),
        ] as $dispatch) {
            Queue::fake();
            $dispatch();

            Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $this->term->id && $job->delay !== null);
        }
    });

    it('no encola al crear grupos sin docente ni al cancelar uno sin docente', function () {
        $free = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->c2->id]);

        GroupsCreated::dispatch($this->term->id, $this->c2->id, [$free->id]);
        GroupCancelled::dispatch($free);

        Queue::assertNotPushed(RefreshTeacherLoads::class);
    });

    it('un cambio de plaza, datos o adscripción encola cada periodo con carga en curso', function () {
        $closed = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['department_id' => $this->department->id]);
        $position = TeacherPosition::factory()->for($this->teacher)->create();

        foreach ([
            fn () => PositionChanged::dispatch($position),
            fn () => TeacherUpdated::dispatch($this->teacher, ['name']),
            fn () => AdscriptionChanged::dispatch($this->teacher->adscriptions()->first()),
        ] as $dispatch) {
            Queue::fake();
            $dispatch();

            Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $this->term->id);
        }

        Queue::assertNotPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $closed->term_id);
    });

    it('las horas externas encolan solo su periodo', function () {
        $load = TeacherExternalLoad::factory()->for($this->teacher)->create(['term_id' => $this->term->id, 'hours' => 4]);

        ExternalLoadChanged::dispatch($load);

        Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $this->term->id);
        Queue::assertPushed(RefreshTeacherLoads::class, 1);
    });

    it('espera uno solo por periodo aunque lleguen varios cambios', function () {
        expect((new RefreshTeacherLoads($this->term->id))->uniqueId())->toBe($this->term->id)
            ->and(new RefreshTeacherLoads($this->term->id))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    });

    it('el job recalcula el periodo', function () {
        (new RefreshTeacherLoads($this->term->id))->handle(app(RefreshTeacherTermLoads::class));

        expect(loadOf($this->teacher, $this->term))->assigned_hours->toBe(5);
    });
});

describe('staffing:teacher-loads', function () {
    it('recalcula el periodo sin pasar por la cola', function () {
        $teacher = loadTeacher($this->department);

        $this->artisan('staffing:teacher-loads', ['term' => $this->term->code])->assertSuccessful();

        expect(loadOf($teacher, $this->term))->not->toBeNull();
    });

    it('falla con un periodo que no existe', function () {
        $this->artisan('staffing:teacher-loads', ['term' => 'nope'])->assertFailed();
    });
});
