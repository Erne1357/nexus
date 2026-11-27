<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Staffing\Enums\RankingState;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Queries\AssignmentBoard;
use App\Modules\Staffing\Queries\AssignmentBoardQuery;
use App\Modules\Staffing\Queries\GroupRankingQuery;
use App\Modules\Staffing\Queries\RankingRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Lecturas de la pantalla «Asignación docente» (tarea 3.9): los grupos del departamento en el periodo y la modalidad
| (M1: de todos sus campus, cada uno con el suyo; el campus es un filtro) con su horario, su docente vigente, los tres
| primeros lugares de su ranking y su estado; el ranking completo y el historial de un grupo.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->iinf = $this->world->career('IINF');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->redes = $this->world->subject('SCD-1021', 'Redes de Computadoras');
});

function boardOf(AssignmentWorld $world, ?Campus $campus = null): AssignmentBoard
{
    return app(AssignmentBoardQuery::class)->board($world->term->id, $world->load->modality_id, $world->department->id, $campus?->id);
}

it('lista los grupos del departamento en el periodo y la modalidad, por semestre, materia, carrera y clave', function () {
    $this->world->group($this->redes, $this->isc, 'H', 5);
    $this->world->group($this->poo, $this->iinf, 'D', 2);
    $this->world->group($this->poo, $this->isc, 'G', 2);
    $this->world->group($this->poo, $this->isc, 'C', 2);
    $this->world->group($this->poo, $this->isc, 'X', 2, attributes: ['status' => GroupStatus::Cancelled]);
    $this->world->group($this->world->subject('ACF-0901', 'Cálculo', Department::factory()->create()), $this->isc, 'A');
    $this->world->group($this->poo, $this->isc, 'B', attributes: ['modality_id' => modalityOf('EAD')->id]);
    $this->world->group($this->poo, $this->isc, 'E', attributes: ['term_id' => Term::factory()->create()->id]);

    $board = boardOf($this->world);

    expect(array_map(fn ($row) => $row->group->ownerCareer->code.' '.$row->group->code, $board->groups))->toBe(['IINF D', 'ISC C', 'ISC G', 'ISC H'])
        ->and($board->groups[0]->group->subject->code)->toBe('SCC-1013')
        ->and($board->total())->toBe(4)
        ->and($board->load->is($this->world->load))->toBeTrue();
});

it('M1: sin filtro muestra los grupos de C1 y C2 del departamento en su modalidad, cada uno con su campus', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group($this->poo, $this->isc, 'D', 2);
    $this->world->group($this->poo, $this->isc, 'E', 2, attributes: ['campus_id' => $c1->id]);

    $board = boardOf($this->world);

    expect(array_map(fn ($row): string => $row->group->code.' '.$row->group->campus->code, $board->groups))->toBe(['D C2', 'E C1'])
        ->and($board->modality->code)->toBe('PRES')
        ->and($board->campus)->toBeNull()
        ->and($board->load->is($this->world->load))->toBeTrue();
});

it('M1: con el filtro de campus muestra solo los grupos de ese campus, con la misma carga', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group($this->poo, $this->isc, 'D', 2);
    $this->world->group($this->poo, $this->isc, 'E', 2, attributes: ['campus_id' => $c1->id]);

    $board = boardOf($this->world, $c1);

    expect(array_map(fn ($row): string => $row->group->code, $board->groups))->toBe(['E'])
        ->and($board->campus->is($c1))->toBeTrue()
        ->and($board->load->is($this->world->load))->toBeTrue();
});

it('resume el horario y las horas por semana del grupo', function () {
    $this->world->group($this->poo, $this->isc, 'L', 2, [[2, '09:00', '11:00'], [4, '09:00', '11:00']]);

    $row = boardOf($this->world)->groups[0];

    expect($row->schedule)->toBe('Ma/Ju 9:00–11:00')
        ->and($row->hours)->toBe('4');
});

it('trae los tres primeros lugares del ranking con su desglose, su plaza y si son de otro departamento', function () {
    $group = $this->world->group($this->poo, $this->isc, 'D', 2);
    $teachers = [
        $this->world->teacher('Ana Ruiz', ['tc', 6]),
        $this->world->teacher('Beto Salas', [12]),
        $this->world->teacher('Carla Díaz', ['hon']),
        $this->world->teacher('Dante Ortiz'),
    ];
    $this->world->rank($group, $teachers, [
        0 => ['remaining_after_hours' => 7],
        1 => ['qualification' => 'apto', 'evaluation' => null, 'flags' => ['sin_evaluacion'], 'from_other_department' => true, 'remaining_hours' => null],
        2 => ['remaining_after_hours' => null],
    ]);

    $row = boardOf($this->world)->groups[0];

    expect($row->rankingSize)->toBe(4)
        ->and($row->rankingState)->toBe(RankingState::Ready)
        ->and(array_map(fn (RankingRow $candidate) => $candidate->teacherName, $row->candidates))->toBe(['Ana Ruiz', 'Beto Salas', 'Carla Díaz'])
        ->and($row->candidates[0])
        ->rank->toBe(1)
        ->score->toBe(20.0)
        ->positions->toBe('TC + Asig. 6 h')
        ->baseHours->toBe(40)
        ->historyCount->toBe(3)
        ->qualification->toBe(QualificationLevel::Preferente)
        ->evaluation->toBe(4.5)
        ->remainingHours->toBe(12)
        ->remainingAfterHours->toBe(7)
        ->fromOtherDepartment->toBeFalse()
        ->and($row->candidates[1])
        ->positions->toBe('Asig. 12 h')
        ->qualification->toBe(QualificationLevel::Apto)
        ->evaluation->toBeNull()
        ->remainingHours->toBeNull()
        ->remainingAfterHours->toBeNull()
        ->fromOtherDepartment->toBeTrue()
        ->and($row->candidates[1]->lacksEvaluation())->toBeTrue()
        ->and($row->candidates[2]->positions)->toBe('Hon.')
        ->and($row->candidates[2]->remainingAfterHours)->toBeNull();
});

it('trae el docente vigente con su asignación, quién lo asignó y su plaza, y cuenta los grupos con docente', function () {
    $assigned = $this->world->group($this->poo, $this->isc, 'D', 2);
    $this->world->group($this->poo, $this->isc, 'G', 2);
    $teacher = $this->world->teacher('Ana Ruiz', ['mt']);
    $jefe = User::factory()->create(['name' => 'Rosa Villalobos']);
    $this->world->assign($assigned, $teacher, $jefe, position: 4, size: 22, reason: 'Pidió conservar el grupo.');

    $board = boardOf($this->world);
    $row = $board->groups[0];

    expect($row->group->teacher->name)->toBe('Ana Ruiz')
        ->and($row->teacherPositions)->toBe('MT')
        ->and($row->assignment->ranking_position_at_assignment)->toBe(4)
        ->and($row->assignment->ranking_size_at_assignment)->toBe(22)
        ->and($row->assignment->assignedBy->name)->toBe('Rosa Villalobos')
        ->and($board->assignedCount())->toBe(1)
        ->and($board->total())->toBe(2)
        ->and($board->groups[1]->assignment)->toBeNull();
});

it('un grupo sin renglones espera el ranking hasta que el departamento se recalcula después de crearlo', function () {
    $ranked = $this->world->group($this->poo, $this->isc, 'D', 2);
    $this->world->rank($ranked, [$this->world->teacher('Ana Ruiz')], computedAt: now()->subHour());
    $this->world->group($this->poo, $this->isc, 'G', 2);

    expect(boardOf($this->world)->groups[1]->rankingState)->toBe(RankingState::Pending);

    DB::table('staffing_rankings')->update(['computed_at' => now()->addMinute()]);

    expect(boardOf($this->world)->groups[1]->rankingState)->toBe(RankingState::Empty);
});

it('lee el tablero con las mismas consultas sin importar cuántos grupos tenga', function () {
    $teachers = [$this->world->teacher('Ana Ruiz'), $this->world->teacher('Beto Salas'), $this->world->teacher('Carla Díaz'), $this->world->teacher('Dante Ortiz')];
    $jefe = User::factory()->create();
    $build = function (int $from, int $to) use ($teachers, $jefe): void {
        foreach (range($from, $to) as $i) {
            $group = $this->world->group($i % 2 ? $this->poo : $this->redes, $i % 3 ? $this->isc : $this->iinf, "G{$i}", 2, [[1 + $i % 5, sprintf('%02d:00', 7 + $i), sprintf('%02d:00', 8 + $i)]]);
            $this->world->rank($group, $teachers);
            if ($i % 2 === 0) {
                $this->world->assign($group, $teachers[$i % 4], $jefe);
            }
        }
    };
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        boardOf($this->world);

        return count(DB::getQueryLog());
    };

    $build(1, 2);
    $few = $count();
    $build(3, 10);

    expect($count())->toBe($few)->toBeLessThanOrEqual(16);
});

it('el ranking completo va en orden y el historial, del más reciente al más antiguo, con su estado', function () {
    $group = $this->world->group($this->poo, $this->isc, 'D', 2);
    $other = $this->world->group($this->redes, $this->isc, 'H', 5);
    $teachers = [$this->world->teacher('Ana Ruiz'), $this->world->teacher('Beto Salas'), $this->world->teacher('Carla Díaz')];
    $this->world->rank($group, array_reverse($teachers));
    $jefe = User::factory()->create(['name' => 'Rosa Villalobos']);
    GroupTeacherAssignment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teachers[0]->id, 'assigned_by' => $jefe->id, 'assigned_at' => now()->subDays(3), 'replaced_at' => now()->subDays(2), 'replaced_by' => $jefe->id]);
    GroupTeacherAssignment::factory()->outsideTop()->create(['group_id' => $group->id, 'teacher_id' => $teachers[1]->id, 'assigned_by' => $jefe->id, 'assigned_at' => now()->subDay()]);
    GroupTeacherAssignment::factory()->create(['group_id' => $other->id, 'teacher_id' => $teachers[2]->id, 'assigned_by' => $jefe->id, 'assigned_at' => now()->subDays(3), 'replaced_at' => now()->subDays(2), 'replaced_by' => $jefe->id]);

    $rankings = app(GroupRankingQuery::class)->forGroups($this->world->term, [$group->id, $other->id]);
    $history = $rankings[$group->id]->history;

    expect(array_map(fn (RankingRow $row) => $row->teacherName, $rankings[$group->id]->rows))->toBe(['Carla Díaz', 'Beto Salas', 'Ana Ruiz'])
        ->and(array_map(fn (array $entry) => [$entry['assignment']->teacher->name, $entry['status']], $history))->toBe([['Beto Salas', 'vigente'], ['Ana Ruiz', 'reemplazada']])
        ->and($history[0]['assignment']->reason)->toBe('Prefiere este grupo y tiene la disponibilidad.')
        ->and($history[0]['assignment']->assignedBy->name)->toBe('Rosa Villalobos')
        ->and($rankings[$other->id]->rows)->toBe([])
        ->and($rankings[$other->id]->history[0]['status'])->toBe('quitada');
});

it('dice el lugar de un docente en el ranking del grupo y de cuántos', function () {
    $group = $this->world->group($this->poo, $this->isc, 'D', 2);
    $teachers = [$this->world->teacher('Ana Ruiz'), $this->world->teacher('Beto Salas')];
    $this->world->rank($group, $teachers);
    $query = app(GroupRankingQuery::class);

    expect($query->place($group, $teachers[1]->id))->toBe([2, 2])
        ->and($query->place($group, $this->world->teacher('Fuera')->id))->toBe([null, 2]);
});
