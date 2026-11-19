<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Queries\BuildSolverInput;
use Illuminate\Support\Facades\DB;
use Tests\Support\Solver\Verdicts;

/*
| La prueba de equivalencia de la tarea 6.5: el veredicto del evaluador en memoria sobre el contrato del motor
| (ContractState con BuildSolverInput y las sesiones actuales) es el mismo que el del validador de la fase 3 contra la
| base (ScheduleValidator sobre ScheduleStateQuery): las mismas reglas duras violadas por cada grupo movible. Un
| escenario por regla, más los casos que el contrato traduce: ocupación de otra modalidad, grupo fijo de otro
| departamento, tronco común, préstamos y el «falso único» de HC-03.
*/

beforeEach(function () {
    DB::statement('set constraints group_sessions_teacher_no_overlap, group_sessions_room_no_overlap deferred');

    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->s1 = Campus::factory()->create(['code' => 'S1']);
    $this->s2 = Campus::factory()->create(['code' => 'S2']);

    $this->sistemas = Department::factory()->create(['name' => 'Sistemas']);
    $this->basicas = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $this->isc = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->s1->id]);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->s2->id]);

    $this->prog = equivalenceSubject($this->sistemas, 'SCC-1010');
    $this->bd = equivalenceSubject($this->sistemas, 'SCD-1020');
    $this->lab = equivalenceSubject($this->sistemas, 'SCL-1030', ['theory_hours' => 0, 'practice_hours' => 2, 'needs_lab' => true]);
    $this->calc = equivalenceSubject($this->basicas, 'AEF-1041');

    $this->t1 = Teacher::factory()->create();
    $this->t2 = Teacher::factory()->create();
    $this->t3 = Teacher::factory()->create();
    TeacherPosition::factory()->for($this->t1)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->t2)->tiempoCompleto()->create();

    $this->room = equivalenceRoom($this->s1);
    $this->small = equivalenceRoom($this->s1, ['capacity' => 20]);
    $this->owned = equivalenceRoom($this->s1, ['department_id' => $this->basicas->id]);
    $this->roomS2 = equivalenceRoom($this->s2);
});

/**
 * Una materia de 2 h de teoría, para que una sesión de 2 h cumpla HC-12.
 *
 * @param  array<string, mixed>  $attributes
 */
function equivalenceSubject(Department $department, string $code, array $attributes = []): Subject
{
    return Subject::factory()->create(['department_id' => $department->id, 'code' => $code, 'theory_hours' => 2, 'practice_hours' => 0, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function equivalenceRoom(Campus $campus, array $attributes = []): Room
{
    return Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'capacity' => 40, 'department_id' => null, ...$attributes]);
}

/**
 * Un grupo presencial de ISC en S1, semestre 1, de 30 lugares.
 *
 * @param  array<string, mixed>  $attributes
 */
function equivalenceGroup(Subject $subject, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->s1->id,
        'modality_id' => test()->pres->id,
        'subject_id' => $subject->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => 1,
        'capacity' => 30,
        ...$attributes,
    ]);
}

function equivalenceSession(Group $group, int $day, string $startsAt, string $endsAt, ?Room $room = null, bool $practice = false): void
{
    $factory = GroupSession::factory()->for($group)->on($day, $startsAt, $endsAt);
    $factory = $room === null ? $factory : $factory->inRoom($room);

    ($practice ? $factory->practica() : $factory)->create();
}

function equivalenceInput(): SolverInput
{
    return app(BuildSolverInput::class)->forDepartment(test()->term, test()->pres, test()->sistemas, 'run-equivalence')->input;
}

/**
 * Las reglas duras de cada grupo movible de la carga de Sistemas: contra la base y en memoria sobre el contrato.
 *
 * @return array{array<string, list<string>>, array<string, list<string>>}
 */
function equivalenceVerdicts(): array
{
    return Verdicts::of(equivalenceInput(), app(ScheduleStateQuery::class)->forModality(test()->term, test()->pres->id));
}

dataset('one database scenario per hard rule', [
    'HC-01 entre dos grupos de la carga' => [function () {
        $a = equivalenceGroup(test()->prog, ['teacher_id' => test()->t1->id]);
        $b = equivalenceGroup(test()->bd, ['teacher_id' => test()->t1->id, 'semester' => 2]);
        equivalenceSession($a, 1, '07:00', '09:00');
        equivalenceSession($b, 1, '08:00', '10:00');

        return [$a->id => ['HC-01'], $b->id => ['HC-01']];
    }],
    'HC-01 con la ocupación del docente en otra modalidad' => [function () {
        $a = equivalenceGroup(test()->prog, ['teacher_id' => test()->t1->id]);
        $ead = equivalenceGroup(test()->calc, ['teacher_id' => test()->t1->id, 'modality_id' => test()->ead->id]);
        equivalenceSession($a, 1, '07:00', '09:00');
        equivalenceSession($ead, 1, '08:00', '10:00');

        return [$a->id => ['HC-01']];
    }],
    'HC-02 con un grupo fijo de otro departamento' => [function () {
        $a = equivalenceGroup(test()->prog);
        $fixed = equivalenceGroup(test()->calc, ['semester' => 3]);
        equivalenceSession($a, 2, '07:00', '09:00', test()->room);
        equivalenceSession($fixed, 2, '08:00', '10:00', test()->room);

        return [$a->id => ['HC-02']];
    }],
    'HC-02 con la ocupación del salón por otra modalidad' => [function () {
        $a = equivalenceGroup(test()->prog);
        $ead = equivalenceGroup(test()->calc, ['modality_id' => test()->ead->id]);
        equivalenceSession($a, 2, '07:00', '09:00', test()->room);
        equivalenceSession($ead, 2, '08:00', '10:00', test()->room);

        return [$a->id => ['HC-02']];
    }],
    'HC-03 con el tronco común de otro departamento' => [function () {
        $a = equivalenceGroup(test()->prog);
        $common = equivalenceGroup(test()->calc);
        equivalenceSession($a, 1, '07:00', '09:00');
        equivalenceSession($common, 1, '08:00', '10:00');

        return [$a->id => ['HC-03']];
    }],
    'HC-04' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 6, '07:00', '09:00');

        return [$a->id => ['HC-04']];
    }],
    'HC-05' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 1, '07:00', '09:00', test()->small);

        return [$a->id => ['HC-05']];
    }],
    'HC-06' => [function () {
        $a = equivalenceGroup(test()->lab);
        equivalenceSession($a, 1, '07:00', '09:00', test()->room, practice: true);

        return [$a->id => ['HC-06']];
    }],
    'HC-08 con horas en otra modalidad' => [function () {
        $a = equivalenceGroup(test()->prog, ['teacher_id' => test()->t3->id]);
        $ead = equivalenceGroup(test()->calc, ['teacher_id' => test()->t3->id, 'modality_id' => test()->ead->id]);
        equivalenceSession($a, 1, '07:00', '09:00');
        equivalenceSession($ead, 2, '07:00', '09:00');

        return [$a->id => ['HC-08']];
    }],
    'HC-09' => [function () {
        $a = equivalenceGroup(test()->prog, ['teacher_id' => test()->t1->id]);
        TeacherAvailability::factory()->for(test()->t1)->create(['term_id' => test()->term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00']);
        equivalenceSession($a, 1, '07:00', '09:00');

        return [$a->id => ['HC-09']];
    }],
    'HC-10 sin préstamo' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 4, '07:00', '09:00', test()->owned);

        return [$a->id => ['HC-10']];
    }],
    'HC-10 con el préstamo todavía propuesto' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 4, '07:00', '09:00', test()->owned);
        equivalenceLoan($a, accepted: false);

        return [$a->id => ['HC-10']];
    }],
    'HC-10 con préstamo aceptado no choca' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 4, '07:00', '09:00', test()->owned);
        equivalenceLoan($a, accepted: true);

        return [];
    }],
    'HC-10 en la ventana prestada a otro grupo de su departamento' => [function () {
        $a = equivalenceGroup(test()->prog);
        $b = equivalenceGroup(test()->bd);
        equivalenceSession($a, 4, '07:00', '09:00', test()->owned);
        equivalenceSession($b, 4, '09:00', '11:00', test()->owned);
        equivalenceLoan($a, accepted: true, endsAt: '11:00');

        return [$b->id => ['HC-10']];
    }],
    'HC-11' => [function () {
        $a = equivalenceGroup(test()->prog);
        RoomBlock::factory()->for(test()->room)->create(['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00']);
        equivalenceSession($a, 3, '07:00', '09:00', test()->room);

        return [$a->id => ['HC-11']];
    }],
    'HC-12' => [function () {
        $a = equivalenceGroup(test()->prog);
        equivalenceSession($a, 1, '07:00', '08:00');

        return [$a->id => ['HC-12']];
    }],
    'una carga sin choques en dos campus' => [function () {
        $a = equivalenceGroup(test()->prog, ['teacher_id' => test()->t1->id]);
        $b = equivalenceGroup(test()->bd, ['teacher_id' => test()->t1->id, 'campus_id' => test()->s2->id]);
        equivalenceSession($a, 1, '07:00', '09:00', test()->room);
        equivalenceSession($b, 2, '07:00', '09:00', test()->roomS2);

        return [];
    }],
]);

function equivalenceLoan(Group $group, bool $accepted, string $endsAt = '09:00'): void
{
    $factory = InterdepartmentalAgreement::factory();

    ($accepted ? $factory->accepted() : $factory)->create([
        'term_id' => test()->term->id, 'campus_id' => test()->s1->id, 'group_id' => $group->id, 'room_id' => test()->owned->id,
        'from_department_id' => test()->sistemas->id, 'to_department_id' => test()->basicas->id,
        'day' => 4, 'starts_at' => '07:00', 'ends_at' => $endsAt,
    ]);
}

it('el evaluador en memoria sobre el contrato da las mismas reglas duras por grupo que el validador contra la base', function (Closure $scenario) {
    $expected = $scenario();

    [$fromDatabase, $inMemory] = equivalenceVerdicts();

    ksort($expected);

    expect($fromDatabase)->toBe($expected)
        ->and($inMemory)->toBe($fromDatabase);
})->with('one database scenario per hard rule');

it('HC-03: un grupo de otro departamento cuya materia tiene otro grupo no parece el único: la entrada trae el semestre completo del movible', function () {
    $alone = equivalenceGroup(test()->prog);
    $sharesTeacher = equivalenceGroup(test()->calc, ['teacher_id' => test()->t2->id]);
    $sibling = equivalenceGroup(test()->calc);
    $movable = equivalenceGroup(test()->bd, ['teacher_id' => test()->t2->id, 'semester' => 2]);
    equivalenceSession($alone, 1, '07:00', '09:00');
    equivalenceSession($sharesTeacher, 1, '08:00', '10:00');
    equivalenceSession($sibling, 3, '07:00', '09:00');
    equivalenceSession($movable, 4, '07:00', '09:00');

    $groups = collect(equivalenceInput()->groups)->keyBy('id');
    [$fromDatabase, $inMemory] = equivalenceVerdicts();
    $combination = 'combination:'.test()->plan->id.':1:S1';

    expect($groups[$sharesTeacher->id]->movable)->toBeFalse()
        ->and($groups[$sharesTeacher->id]->cohorts)->toBe([])
        ->and($groups[$sibling->id]->movable)->toBeFalse()
        ->and($groups[$sibling->id]->cohorts)->toBe([$combination])
        ->and($groups[$alone->id]->cohorts)->toContain($combination)
        ->and($fromDatabase)->toBe([])
        ->and($inMemory)->toBe([]);
});

it('HC-03: el núcleo sin combinación con materias de varios grupos de otro departamento viaja en el contrato y el evaluador lo ve igual que el validador', function () {
    $fis = equivalenceSubject(test()->basicas, 'AEF-1042');
    $alone = equivalenceGroup(test()->prog);
    $calcA = equivalenceGroup(test()->calc, ['code' => 'A']);
    $calcB = equivalenceGroup(test()->calc, ['code' => 'B']);
    $physics = equivalenceGroup($fis);
    equivalenceSession($alone, 1, '07:00', '09:00');
    equivalenceSession($calcA, 1, '08:00', '10:00');
    equivalenceSession($calcB, 3, '07:00', '09:00');
    equivalenceSession($physics, 3, '08:00', '10:00');

    $groups = collect(equivalenceInput()->groups)->keyBy('id');
    [$fromDatabase, $inMemory] = equivalenceVerdicts();

    expect($groups->has($calcA->id) && $groups->has($calcB->id) && $groups->has($physics->id))->toBeTrue()
        ->and($fromDatabase)->toBe([$alone->id => ['HC-03']])
        ->and($inMemory)->toBe($fromDatabase);
});

it('HC-07 y HC-13 no viajan en el contrato: el validador los ve en la base y el evaluador del contrato no', function () {
    DB::statement('set constraints group_quotas_check deferred');
    $quotas = equivalenceGroup(test()->prog);
    GroupQuota::factory()->create(['group_id' => $quotas->id, 'career_id' => test()->isc->id, 'max_seats' => 40, 'is_owner' => true]);
    $retired = equivalenceGroup(equivalenceSubject(test()->sistemas, 'SCX-0001', ['is_active' => false]));

    [$fromDatabase, $inMemory] = equivalenceVerdicts();

    expect($fromDatabase)->toBe([$quotas->id => ['HC-07'], $retired->id => ['HC-13']])
        ->and($inMemory)->toBe([]);
});
