<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\BuildSolverInput;

/*
| ProposePlacements (tareas 8.7 y 8.3b; docs/arquitectura/solver.md, «Acomodos en la petición»): pide al motor, en la
| petición, acomodos alternativos para unos grupos con todo lo demás fijo. Cada alternativa es completa, sin violaciones
| duras, distinta de las demás y cambia algo. Las siguientes apartan las horas de las anteriores. Tiene un plazo.
*/

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function placementsGroup(string $id, array $changes = []): array
{
    return array_merge([
        'id' => $id, 'campus_code' => 'C1', 'subject_code' => "MAT-{$id}", 'department_id' => 'd001', 'owner_career_id' => 'c001',
        'study_plan_id' => 'p001', 'semester' => 1, 'weekly_hours' => 4, 'practice_hours' => 0, 'needs_lab' => false, 'capacity' => 30,
        'cohorts' => [], 'movable' => true,
    ], $changes);
}

/**
 * Cuatro sesiones de una hora, de lunes a jueves a la hora dada, en el salón.
 *
 * @return list<array<string, mixed>>
 */
function placementsWeek(string $start, ?string $room = 'r1', array $days = [1, 2, 3, 4]): array
{
    $end = sprintf('%02d:00', (int) substr($start, 0, 2) + 1);

    return array_map(fn (int $day): array => array_filter(['day' => $day, 'starts_at' => $start, 'ends_at' => $end, 'room_id' => $room, 'kind' => 'teoria']), $days);
}

/**
 * Una entrada de un campus (C1, de 7:00 a 14:00 de lunes a viernes, bloques de 60 min) con dos salones y el docente t1.
 *
 * @param  list<array<string, mixed>>  $groups
 * @param  array<string, mixed>  $teacher
 */
function placementsInput(array $groups, array $teacher = []): SolverInput
{
    return SolverInput::fromArray([
        'version' => 1, 'run_id' => 'propuesta', 'term' => ['code' => '20271'], 'modality' => ['code' => 'PRES'],
        'campuses' => [['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true]],
        'time_limit_seconds' => 5, 'scope' => ['kind' => 'department', 'id' => 'd001'], 'travel_minutes' => 60,
        'groups' => $groups,
        'teachers' => [array_merge(['id' => 't1', 'remaining_hours' => 40, 'honorarios' => false, 'unavailable' => [], 'preferred' => [], 'busy' => []], $teacher)],
        'rooms' => [
            ['id' => 'r1', 'campus_code' => 'C1', 'type' => 'aula', 'capacity' => 40, 'building_id' => 'b001'],
            ['id' => 'r2', 'campus_code' => 'C1', 'type' => 'aula', 'capacity' => 40, 'building_id' => 'b001'],
        ],
        'agreements' => [], 'subject_room_defaults' => [],
        'hard_rules' => ['HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-05', 'HC-06', 'HC-07', 'HC-08', 'HC-09', 'HC-10', 'HC-11', 'HC-12', 'HC-13'],
        'soft_weights' => ['SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2],
    ]);
}

/**
 * La Action con su propio registro de motores y, si se da, un reloj.
 *
 * @param  list<SolverEngine>|null  $engines
 */
function placementsAction(?array $engines = null, ?Closure $clock = null): ProposePlacements
{
    return new ProposePlacements(app(BuildSolverInput::class), new EngineRegistry($engines ?? [new PhpHeuristicEngine]), new ContractEvaluator, $clock);
}

/**
 * Las sesiones de un grupo en una alternativa.
 *
 * @return list<ProposedSession>
 */
function placementsOf(SolverOutput $output, string $groupId): array
{
    return array_values(array_filter($output->sessions, fn (ProposedSession $session): bool => $session->groupId === $groupId));
}

/**
 * Si dos listas de sesiones se empalman en algún día.
 *
 * @param  list<ProposedSession>  $a
 * @param  list<ProposedSession>  $b
 */
function placementsOverlap(array $a, array $b): bool
{
    foreach ($a as $x) {
        foreach ($b as $y) {
            if ($x->day === $y->day && $x->startsAt < $y->endsAt && $y->startsAt < $x->endsAt) {
                return true;
            }
        }
    }

    return false;
}

it('da acomodos distintos, completos y sin choques duros, que cambian algo y no repiten la hora del grupo', function () {
    $base = placementsInput([placementsGroup('g1', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('07:00')])]);

    $outputs = placementsAction()->propose($base, PhpHeuristicEngine::KEY, 3);
    $current = array_map(fn (array $slot): string => "{$slot['day']}|{$slot['starts_at']}", placementsWeek('07:00'));

    expect($outputs)->toHaveCount(3);

    foreach ($outputs as $i => $output) {
        $sessions = placementsOf($output, 'g1');
        $evaluation = (new ContractEvaluator)->evaluate($base, $output->sessions);

        expect($output->hardViolations)->toBe([])
            ->and($evaluation->hardViolations)->toBe([])
            ->and($output->softScore->total)->toBe($evaluation->softScore->total)
            ->and(array_sum(array_map(fn (ProposedSession $s): int => (int) substr($s->endsAt, 0, 2) - (int) substr($s->startsAt, 0, 2), $sessions)))->toBe(4)
            ->and(array_map(fn (ProposedSession $s): string => "{$s->day}|{$s->startsAt}", $sessions))->not->toEqualCanonicalizing($current);

        foreach (array_slice($outputs, 0, $i) as $before) {
            expect(placementsOverlap($sessions, placementsOf($before, 'g1')))->toBeFalse();
        }
    }
});

it('fija las sesiones propuestas de un grupo y mueve a los demás para dejarlo sin choques (la cadena)', function () {
    $base = placementsInput([
        placementsGroup('g1', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('07:00')]),
        placementsGroup('g2', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('09:00', 'r2')]),
    ]);
    $pinned = array_map(fn (array $slot): SessionSlot => SessionSlot::fromArray($slot), placementsWeek('09:00'));

    $outputs = placementsAction()->propose($base, PhpHeuristicEngine::KEY, 1, ['g1' => $pinned]);

    expect($outputs)->toHaveCount(1)
        ->and(array_map(fn (ProposedSession $s): array => [$s->day, $s->startsAt, $s->roomId, $s->changed], placementsOf($outputs[0], 'g1')))
        ->toBe([[1, '09:00', 'r1', true], [2, '09:00', 'r1', true], [3, '09:00', 'r1', true], [4, '09:00', 'r1', true]])
        ->and(placementsOf($outputs[0], 'g2'))->toHaveCount(4)
        ->and(placementsOverlap(placementsOf($outputs[0], 'g1'), placementsOf($outputs[0], 'g2')))->toBeFalse()
        ->and((new ContractEvaluator)->evaluate($base, $outputs[0]->sessions)->hardViolations)->toBe([]);
});

it('sin alternativa: lo fijado choca con un grupo que no se mueve', function () {
    $base = placementsInput([
        placementsGroup('g1', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('07:00')]),
        placementsGroup('otro', ['teacher_id' => 't1', 'movable' => false, 'fixed_sessions' => placementsWeek('09:00', 'r2')]),
    ]);
    $pinned = array_map(fn (array $slot): SessionSlot => SessionSlot::fromArray($slot), placementsWeek('09:00'));

    expect(placementsAction()->propose($base, PhpHeuristicEngine::KEY, 2, ['g1' => $pinned]))->toBe([]);
});

it('sin alternativa: el único lugar posible es el actual', function () {
    $blocked = array_merge(
        array_map(fn (int $day): array => ['day' => $day, 'starts_at' => '08:00', 'ends_at' => '14:00'], [1, 2, 3, 4]),
        [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '14:00']],
    );
    $base = placementsInput([placementsGroup('g1', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('07:00')])], ['unavailable' => $blocked]);

    expect(placementsAction()->propose($base, PhpHeuristicEngine::KEY, 3))->toBe([]);
});

it('respeta el plazo: cada vuelta le da al motor los segundos que quedan y no empieza otra con menos de uno', function () {
    $time = new stdClass;
    $time->now = 100.0;
    $time->limits = [];
    $clock = fn (): float => $time->now;
    $engine = new class(new PhpHeuristicEngine(clock: $clock), $time) implements SolverEngine
    {
        public function __construct(private readonly PhpHeuristicEngine $inner, private readonly stdClass $time) {}

        public function key(): string
        {
            return 'reloj';
        }

        public function label(): string
        {
            return 'Con reloj';
        }

        public function supportedVersions(): array
        {
            return [1];
        }

        public function checksHardRules(): bool
        {
            return true;
        }

        public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
        {
            $this->time->limits[] = $input->timeLimitSeconds;
            $output = $this->inner->solve($input, $progress);
            $this->time->now += 1.6;

            return $output;
        }
    };
    $base = placementsInput([placementsGroup('g1', ['teacher_id' => 't1', 'current_sessions' => placementsWeek('07:00')])]);

    $outputs = placementsAction([$engine], $clock)->propose($base, 'reloj', 5);

    expect(ProposePlacements::TIME_LIMIT_SECONDS)->toBe(5)
        ->and($time->limits)->toBe([5, 3, 1])
        ->and(count($outputs))->toBeLessThanOrEqual(3);
});

it('rechaza un motor que no revisa reglas duras y fijar un grupo que no se mueve', function (string $case) {
    $base = placementsInput([
        placementsGroup('g1', ['teacher_id' => 't1']),
        placementsGroup('otro', ['movable' => false, 'fixed_sessions' => placementsWeek('09:00', 'r2')]),
    ]);

    match ($case) {
        'motor' => placementsAction([new FixedEngine])->propose($base, 'fixed'),
        'fijar' => placementsAction()->propose($base, PhpHeuristicEngine::KEY, 1, ['otro' => []]),
    };
})->with(['motor', 'fijar'])->throws(InvalidArgumentException::class);

it('desde la base: arma la entrada de los grupos pedidos, propone para un grupo virtual y no escribe nada', function () {
    $term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $campus = Campus::factory()->create(['code' => 'P1']);
    $department = Department::factory()->create();
    $career = Career::factory()->create(['department_id' => $department->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $subject = Subject::factory()->create(['department_id' => $department->id, 'code' => 'SCC-1', 'theory_hours' => 4, 'practice_hours' => 0]);
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'capacity' => 40]);
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
    $group = Group::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id, 'subject_id' => $subject->id,
        'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'semester' => 1, 'capacity' => 30, 'teacher_id' => $teacher->id]);

    foreach ([1, 2, 3, 4] as $day) {
        GroupSession::factory()->for($group)->inRoom($room)->on($day, '07:00', '08:00')->create();
    }

    $virtual = new GroupInput("new:SCC-1:P1:{$career->id}", 'P1', 'SCC-1', $department->id, $career->id, $plan->id, 1, 4, 0, false, 30, $teacher->id, [], [], [], true);

    $outputs = app(ProposePlacements::class)->handle($term, modalityOf('PRES'), [], PhpHeuristicEngine::KEY, 2, [$virtual]);

    expect($outputs)->not->toBeEmpty()
        ->and(placementsOf($outputs[0], $virtual->id))->toHaveCount(4)
        ->and(collect(placementsOf($outputs[0], $virtual->id))->every(fn (ProposedSession $s): bool => $s->startsAt !== '07:00' && $s->kind === SessionKind::Theory))->toBeTrue()
        ->and(GroupSession::query()->count())->toBe(4)
        ->and(SolverRun::query()->count())->toBe(0);
});
