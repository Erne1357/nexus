<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Queries\ClosingBlockersQuery;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\ClosingBoardQuery;
use App\Modules\Staffing\Queries\LoadProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staffing\ClosingWorld;

/*
| ClosingBoardQuery (tarea 4.2): «Avance del cierre» de todo el Tec. Un renglón por departamento con una entrada por
| carga con grupos y lo que le falta a cada una, contado por grupo; los choques duros son los vigentes de
| `schedule_conflicts` (no el validador) y todo se lee en pocas consultas.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
});

/**
 * @return array<string, mixed>
 */
function closingBoardOf(string $search = '', string $state = ''): array
{
    return app(ClosingBoardQuery::class)->board(test()->world->term, $search, $state);
}

/**
 * @param  array<string, mixed>  $board
 * @return array<string, LoadProgress>
 */
function closingBoardLoads(array $board, string $code): array
{
    $row = collect($board['rows'])->first(fn (array $row): bool => $row['department']->code === $code);

    return collect($row['loads'])->mapWithKeys(fn (LoadProgress $load): array => [$load->load->campus->code => $load])->all();
}

it('un renglón por departamento con una entrada por campus con grupos y lo que le falta a cada carga', function () {
    $jefe = $this->world->head();
    $c1Load = $this->world->loadIn($this->c1, LoadStatus::Published);
    $c1Load->update(['current_version' => 2]);
    $this->world->group(load: $c1Load);
    $this->world->group();
    $this->world->group(teacher: false);
    $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $jefe);
    $this->world->group(scheduled: false);
    $this->world->group(withRoom: false);
    $this->world->group(teacher: $this->world->teacher(active: false));
    ChangeRequest::factory()->forGroup($this->world->group())->pending()->create();
    $clashing = $this->world->group();
    ScheduleConflict::factory()->create(['group_id' => $clashing->id, 'term_id' => $clashing->term_id, 'campus_id' => $clashing->campus_id, 'department_id' => $clashing->department_id]);
    ScheduleConflict::factory()->create(['group_id' => $clashing->id, 'term_id' => $clashing->term_id, 'campus_id' => $clashing->campus_id, 'department_id' => $clashing->department_id, 'rule' => 'HC-01']);
    $resolved = $this->world->group();
    ScheduleConflict::factory()->create(['group_id' => $resolved->id, 'term_id' => $resolved->term_id, 'campus_id' => $resolved->campus_id, 'department_id' => $resolved->department_id, 'resolved_at' => now()]);

    $loads = closingBoardLoads(closingBoardOf(), 'SC');

    expect(array_keys($loads))->toBe(['C1', 'C2'])
        ->and($loads['C1']->bucket())->toBe('published')
        ->and($loads['C2'])->toMatchObject([
            'groups' => 9,
            'hard' => 1,
            'unscheduled' => 1,
            'withoutTeacher' => 1,
            'withoutRoom' => 1,
            'inactiveTeacher' => 1,
            'requests' => 1,
            'justified' => 1,
        ])
        ->and($loads['C2']->others())->toBe(2)
        ->and($loads['C2']->blockingGroups)->toBe(5)
        ->and($loads['C2']->procedures())->toBe(1)
        ->and($loads['C2']->missing())->toBe(6)
        ->and($loads['C2']->bucket())->toBe('missing');
});

it('HC-15: cuenta por grupo lo mismo que el veto (ClosingBlockersQuery), con la misma regla', function () {
    $jefe = $this->world->head();
    $this->world->group();
    $this->world->group(teacher: false, withRoom: false);
    $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $jefe);
    $this->world->justify($this->world->group(withRoom: false), 'El laboratorio se entrega en enero', $jefe);
    $this->world->group(scheduled: false);
    $this->world->group(teacher: $this->world->teacher(active: false));

    $veto = app(ClosingBlockersQuery::class)->of($this->world->load->fresh());
    $board = closingBoardLoads(closingBoardOf(), 'SC')['C2'];

    expect([$board->hard, $board->unscheduled, $board->withoutTeacher, $board->withoutRoom, $board->inactiveTeacher, $board->justified, $board->blockingGroups])
        ->toBe([$veto->conflicted, $veto->unscheduled, $veto->withoutTeacher, $veto->withoutRoom, $veto->inactiveTeacher, $veto->justified, $veto->groups])
        ->and($veto->groups)->toBe(3);
});

it('«C2 · N» cuenta grupos distintos: un grupo sin docente ni salón cuenta una vez', function () {
    $this->world->group(teacher: false, withRoom: false);
    $this->world->group(teacher: false);
    $this->world->group();

    $load = closingBoardLoads(closingBoardOf(), 'SC')['C2'];

    expect($load->withoutTeacher)->toBe(2)
        ->and($load->withoutRoom)->toBe(1)
        ->and($load->blockingGroups)->toBe(2)
        ->and($load->missing())->toBe(2);
});

it('cuenta los acuerdos que detienen el cierre con la regla de AgreementsAnswered', function () {
    $other = Department::factory()->create();
    $this->world->group();
    $base = ['term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id];
    InterdepartmentalAgreement::factory()->sharedGroup()->create([...$base, 'from_department_id' => $this->world->department->id, 'to_department_id' => $other->id]);
    InterdepartmentalAgreement::factory()->create([...$base, 'from_department_id' => $other->id, 'to_department_id' => $this->world->department->id]);
    InterdepartmentalAgreement::factory()->create([...$base, 'from_department_id' => $this->world->department->id, 'to_department_id' => $other->id]);

    $load = closingBoardLoads(closingBoardOf(), 'SC')['C2'];

    expect($load->shared)->toBe(1)
        ->and($load->awaiting)->toBe(1);
});

it('cuenta las cubetas de todo el Tec: con faltantes, listas, cerradas y publicadas', function () {
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $ee = Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $this->world->group(teacher: false);
    $this->world->group(load: $this->world->loadIn($this->c1));
    $this->world->group(load: $this->world->loadIn($this->world->campus, LoadStatus::Closed, $cb));
    $this->world->group(load: $this->world->loadIn($this->c1, LoadStatus::Published, $cb));
    $this->world->group(load: $this->world->loadIn($this->world->campus, LoadStatus::Adjusting, $ee));

    $board = closingBoardOf();

    expect($board['kpis'])->toBe(['missing' => 2, 'ready' => 1, 'closed' => 1, 'published' => 1, 'total' => 5])
        ->and(closingBoardLoads($board, 'EE')['C2']->bucket())->toBe('missing');
});

it('a una carga publicada no le cuenta faltantes: ya pasó el cierre', function () {
    $published = $this->world->loadIn($this->c1, LoadStatus::Published);
    $this->world->group(teacher: false, load: $published);
    $this->world->group(load: $published);

    $load = closingBoardLoads(closingBoardOf(), 'SC')['C1'];

    expect($load->groups)->toBe(2)
        ->and($load->missing())->toBe(0)
        ->and($load->bucket())->toBe('published');
});

it('un departamento sin grupos en ningún campus va al final, sin cargas', function () {
    $lo = Department::factory()->create(['code' => 'LO', 'name' => 'Administración']);
    $this->world->loadIn($this->world->campus, LoadStatus::Draft, $lo);
    $this->world->group();

    $rows = closingBoardOf()['rows'];

    expect(array_map(fn (array $row): string => $row['department']->code, $rows))->toBe(['SC', 'LO'])
        ->and($rows[1]['loads'])->toBe([])
        ->and(closingBoardOf()['kpis']['total'])->toBe(1);
});

it('filtra por clave o nombre sin importar acentos y por la cubeta de alguna carga; las cifras de arriba no cambian', function () {
    $ee = Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $this->world->group();
    $this->world->group(load: $this->world->loadIn($this->world->campus, LoadStatus::Closed, $ee));

    expect(array_map(fn (array $row): string => $row['department']->code, closingBoardOf('electrica')['rows']))->toBe(['EE'])
        ->and(array_map(fn (array $row): string => $row['department']->code, closingBoardOf(state: 'ready')['rows']))->toBe(['SC'])
        ->and(closingBoardOf(state: 'closed')['totals'])->toMatchArray(['groups' => 1, 'loads' => 1])
        ->and(closingBoardOf('electrica')['kpis']['total'])->toBe(2);
});

it('dice cuándo se recalcularon los choques: el más viejo de los campus, y cuáles no se han recalculado', function () {
    $this->world->group();
    $this->world->group(load: $this->world->loadIn($this->c1));
    Cache::forever(SyncScheduleConflicts::stampKey($this->world->term->id, $this->world->campus->id), now()->setTime(9, 15)->toIso8601String());

    $board = closingBoardOf();

    expect($board['syncedAt']->format('H:i'))->toBe('09:15')
        ->and($board['unsynced'])->toBe(['C1']);
});

it('da el jefe de cada departamento: quien tiene vigente pasar la carga a asignación, no Subdirección', function () {
    $ee = Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $this->world->head();
    User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Héctor Salinas']);
    $this->world->group();
    $this->world->group(load: $this->world->loadIn($this->world->campus, department: $ee));

    $board = closingBoardOf();

    expect(app(ClosingBoardQuery::class)->heads($board['rows']))->toBe([
        $ee->id => [],
        $this->world->department->id => ['Laura Ramos'],
    ]);
});

it('trae a los titulares una vez para todos los renglones, sin importar cuántos departamentos haya', function () {
    $departments = collect(range(1, 4))->map(function (int $index): Department {
        $department = Department::factory()->create(['code' => "D{$index}"]);
        User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(['name' => "Jefe {$index}"]);
        $this->world->group(load: $this->world->loadIn($this->world->campus, department: $department));

        return $department;
    });
    $rows = closingBoardOf()['rows'];

    DB::enableQueryLog();
    $heads = app(ClosingBoardQuery::class)->heads($rows);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"'))->count())->toBe(1)
        ->and($departments->mapWithKeys(fn (Department $department): array => [$department->id => $heads[$department->id]])->values()->all())
        ->toBe([['Jefe 1'], ['Jefe 2'], ['Jefe 3'], ['Jefe 4']]);
});

it('lee todo el Tec en pocas consultas, sin importar cuántas cargas haya', function () {
    foreach (range(1, 6) as $index) {
        $department = Department::factory()->create();
        $this->world->group(load: $this->world->loadIn($this->world->campus, department: $department));
        $this->world->group(load: $this->world->loadIn($this->c1, department: $department));
    }

    DB::enableQueryLog();
    closingBoardOf();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(7);
});
