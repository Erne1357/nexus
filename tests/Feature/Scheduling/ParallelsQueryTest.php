<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Scheduling\Queries\Parallels;
use App\Modules\Scheduling\Queries\ParallelSlot;
use App\Modules\Scheduling\Queries\ParallelsQuery;
use App\Modules\Scheduling\Queries\ParallelSubject;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| La pestaña «Paralelos» de la bandeja (tarea 8.8, opción B; reglas-de-negocio.md, «Paralelos»): por materia y campus, lo
| que piden las carreras con su parte aceptada o pendiente, los grupos sueltos que ya existen y la sugerencia de cómo
| juntarlos. En un mixto cada carrera recibe sus esperados en exclusiva y lo demás queda libre (confirmado el 18 de
| noviembre de 2026); los grupos de paquete no se juntan.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->isic = $this->world->career('ISIC');
    $this->iind = $this->world->career('IIND');
});

function parallelsFor(?ParallelsWorld $world = null): Parallels
{
    $world ??= test()->world;

    return app(ParallelsQuery::class)->for($world->term, $world->modality, $world->department);
}

function parallelsRow(?Subject $subject = null, ?Campus $campus = null): ParallelSubject
{
    $row = parallelsFor()->subject(($subject ?? test()->world->subject)->id, ($campus ?? test()->world->campus)->id);

    expect($row)->not->toBeNull();

    return $row;
}

/**
 * Los grupos de la sugerencia con claves de carrera: sus cupos, su capacidad y el grupo que se queda (su clave) o null.
 *
 * @return list<array{quotas: array<string, int>, capacity: int, keeps: ?string}>
 */
function parallelsSlots(ParallelSubject $row): array
{
    $codes = Career::query()->pluck('code', 'id')->all();

    return array_map(fn (ParallelSlot $slot): array => [
        'quotas' => collect($slot->quotas)->mapWithKeys(fn (int $seats, string $id): array => [$codes[$id] => $seats])->all(),
        'capacity' => $slot->capacity,
        'keeps' => $slot->keeps?->code,
    ], $row->suggestion);
}

it('junta los restos en un mixto donde cada carrera recibe sus esperados en exclusiva y lo demás queda libre', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);

    $row = parallelsRow();

    expect($row->requested())->toBe(4)
        ->and($row->suggested())->toBe(3)
        ->and($row->saved())->toBe(1)
        ->and($row->whyNot)->toBeNull()
        ->and(parallelsSlots($row))->toBe([
            ['quotas' => ['IIND' => 35], 'capacity' => 35, 'keeps' => null],
            ['quotas' => ['ISIC' => 35], 'capacity' => 35, 'keeps' => null],
            ['quotas' => ['ISIC' => 25, 'IIND' => 5], 'capacity' => 35, 'keeps' => null],
        ])
        ->and($row->suggestion[2]->isMixed())->toBeTrue()
        ->and($row->suggestion[2]->free())->toBe(5)
        ->and($row->suggestion[2]->over())->toBe(0);

    expect(parallelsFor()->figures())->toBe(['subjects' => 1, 'requested' => 4, 'suggested' => 3, 'saved' => 1, 'arranged' => 0]);
});

it('muestra por carrera lo que pide, los esperados del reparto y sus grupos sueltos con cupos, docente y sesiones', function () {
    $item = $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    [$first, $second] = $this->world->groupsOf($item, 2);
    $this->world->withTeacher($first);
    $this->world->withSession($second, hours: 3);

    $row = parallelsRow();
    $isic = collect($row->careers)->first(fn ($career): bool => $career->career->is($this->isic));

    expect($isic->status)->toBe(InboxStatus::Accepted)
        ->and([$isic->expected, $isic->requested, $isic->item->is($item)])->toBe([60, 2, true])
        ->and(array_map(fn ($group): array => [$group->code, $group->teacher, $group->sessions, $group->minutes, $group->quotas], $row->groupsOf($this->isic->id)))
        ->toBe([['1', 'Mtra. Laura Benavides', 0, 0, [$this->isic->id => 35]], ['2', null, 1, 180, [$this->isic->id => 35]]])
        ->and(parallelsSlots($row))->toBe([
            ['quotas' => ['IIND' => 35], 'capacity' => 35, 'keeps' => null],
            ['quotas' => ['ISIC' => 35], 'capacity' => 35, 'keeps' => '1'],
            ['quotas' => ['ISIC' => 25, 'IIND' => 5], 'capacity' => 35, 'keeps' => '2'],
        ])
        ->and($row->cancelled)->toBe([]);
});

it('los grupos de paquete no se juntan: solo cuentan los sueltos y los rezagados', function () {
    $item = $this->world->request($this->isic, 44, 2, packageGroups: 1);
    $item->update(['forecast_method' => ForecastMethod::FirstSemester]);
    ProposalIntake::factory()->create(['career_proposal_id' => $item->career_proposal_id, 'campus_id' => $this->world->campus->id, 'new_students' => 30]);
    $package = ProposalPackage::factory()->create(['career_proposal_id' => $item->career_proposal_id]);
    $this->world->groupsOf($item, 1, ['package_id' => $package->id, 'package_number' => 1]);
    $this->world->groupsOf($item);
    $this->world->request($this->iind, 40, 2);

    $row = parallelsRow();
    $isic = collect($row->careers)->first(fn ($career): bool => $career->career->is($this->isic));

    expect([$isic->expected, $isic->requested, $isic->newStudents])->toBe([14, 1, 30])
        ->and(array_map(fn ($group): string => $group->code, $row->groups))->toBe(['2'])
        ->and($row->requested())->toBe(3)
        ->and(parallelsSlots($row))->toBe([
            ['quotas' => ['IIND' => 35], 'capacity' => 35, 'keeps' => null],
            ['quotas' => ['ISIC' => 14, 'IIND' => 5], 'capacity' => 35, 'keeps' => '2'],
        ]);
});

it('la parte regresada no cuenta y la pendiente cuenta pero no se arma hasta aceptarla', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2, 'pending');
    $this->world->request($this->world->career('IGEM'), 30, 1, 'returned');

    $row = parallelsRow();

    expect(array_map(fn ($career): string => $career->career->code, $row->careers))->toBe(['IIND', 'ISIC'])
        ->and(array_map(fn ($career): array => [$career->career->code, $career->status], $row->excluded))->toBe([['IGEM', InboxStatus::Returned]])
        ->and($row->requested())->toBe(4)
        ->and(array_map(fn ($career): string => $career->career->code, $row->pending()))->toBe(['IIND'])
        ->and($row->arrangeBlockedReason())->toBe('Falta aceptar la parte de IIND: sus lugares nacen al aceptarla.');
});

it('dice por qué no hay nada que juntar', function () {
    $this->world->request($this->isic, 60, 2);

    expect(parallelsRow()->whyNot)->toBe('Solo la pide ISIC')
        ->and(parallelsFor()->joinable())->toBe([])
        ->and(parallelsFor()->nothingToJoin())->toHaveCount(1);

    $this->world->request($this->iind, 30, 1);
    expect(parallelsRow()->whyNot)->toBe('Juntando no se ahorran grupos');
});

it('señala el grupo con docente que la sugerencia tendría que cancelar', function () {
    $isic = $this->world->request($this->isic, 30, 2);
    $this->world->request($this->iind, 5, 1);
    [$first, $second] = $this->world->groupsOf($isic, 2);
    $this->world->withTeacher($second);

    $row = parallelsRow();

    expect(parallelsSlots($row))->toBe([['quotas' => ['ISIC' => 30, 'IIND' => 5], 'capacity' => 35, 'keeps' => '2']])
        ->and(array_map(fn ($group): string => $group->code, $row->cancelled))->toBe(['1'])
        ->and($row->blocked())->toBe([])
        ->and($row->arrangeBlockedReason())->toBeNull();

    $this->world->withTeacher($first);
    $row = parallelsRow();

    expect(array_map(fn ($group): string => $group->code, $row->blocked()))->toBe(['2'])
        ->and($row->arrangeBlockedReason())->toBe('El grupo 2 de ISIC tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de ISIC o quítale primero el docente.');
});

it('una carrera sin esperados no cuenta: no suma grupos pedidos ni cambia por qué no hay nada que juntar', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->world->career('IGEM'), 0, 1);

    $row = parallelsRow();

    expect(array_map(fn ($career): string => $career->career->code, $row->careers))->toBe(['ISIC'])
        ->and(array_map(fn ($career): array => [$career->career->code, $career->status], $row->excluded))->toBe([['IGEM', InboxStatus::Accepted]])
        ->and($row->requested())->toBe(2)
        ->and($row->whyNot)->toBe('Solo la pide ISIC');

    ProposalItem::query()->where('expected_students', 60)->update(['expected_students' => 0]);

    expect(parallelsRow()->whyNot)->toBe('Ninguna carrera espera alumnos');
});

it('la sugerencia no reparte más allá de la capacidad real del grupo que se queda: lo que no cabe queda de menos', function () {
    $isic = $this->world->request($this->isic, 30, 1);
    $this->world->request($this->iind, 5, 1);
    $this->world->groupsOf($isic, 1, ['capacity' => 25]);

    $row = parallelsRow();

    expect(parallelsSlots($row))->toBe([['quotas' => ['ISIC' => 20, 'IIND' => 5], 'capacity' => 25, 'keeps' => '1']])
        ->and($row->short)->toBe([$this->isic->id => 10])
        ->and($row->suggestion[0]->over())->toBe(0)
        ->and($row->arrangeBlockedReason())->toBeNull();
});

it('lo que no cabe en un grupo pasa a los lugares libres de un mixto antes de quedar de menos', function () {
    $isic = $this->world->request($this->isic, 30, 1);
    $this->world->request($this->iind, 20, 1);
    $this->world->request($this->world->career('ITIC'), 10, 1);
    $this->world->groupsOf($isic, 1, ['capacity' => 25]);

    $row = parallelsRow();

    expect(parallelsSlots($row))->toBe([
        ['quotas' => ['ISIC' => 25], 'capacity' => 25, 'keeps' => '1'],
        ['quotas' => ['IIND' => 20, 'ITIC' => 10, 'ISIC' => 5], 'capacity' => 35, 'keeps' => null],
    ])->and($row->short)->toBe([]);
});

it('marca como bloqueado el grupo con una solicitud de cambio abierta que la sugerencia cancelaría', function () {
    $isic = $this->world->request($this->isic, 30, 2);
    $this->world->request($this->iind, 5, 1);
    [$first, $second] = $this->world->groupsOf($isic, 2);
    ChangeRequest::factory()->forGroup($first)->create();
    ChangeRequest::factory()->forGroup($second)->create();

    $row = parallelsRow();

    expect(array_map(fn ($group): string => $group->code, $row->blocked()))->toBe(['2'])
        ->and($row->arrangeBlockedReason())->toBe('Resuelve primero la solicitud de cambio de Cálculo Integral, grupo 2.');
});

it('marca la materia armada cuando un grupo suelto ya junta a dos carreras', function () {
    $isic = $this->world->request($this->isic, 30, 1);
    $this->world->request($this->iind, 5, 1);
    [$group] = $this->world->groupsOf($isic);
    $group->quotas()->update(['max_seats' => 30]);
    $group->quotas()->create(['career_id' => $this->iind->id, 'max_seats' => 5]);

    expect(parallelsRow()->arranged())->toBeTrue()
        ->and(parallelsFor()->figures()['arranged'])->toBe(1);
});

it('con la carga fuera de propuesta, asignación o ajustes dice por qué no se arma', function () {
    $this->world->load->update(['status' => LoadStatus::Published]);

    expect(parallelsFor()->blockedReason)->toBe('La carga de Ciencias Básicas en Presencial ya está publicada: no se editan paralelos.');
});

it('lee la pestaña con un número fijo de consultas, sin importar cuántas materias, carreras o grupos haya', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        parallelsFor();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $this->world->groupsOf($this->world->request($this->isic, 60, 2), 2);
    $this->world->request($this->iind, 40, 2);
    $few = $count();

    $c2 = Campus::factory()->create(['code' => 'C2']);

    foreach (range(1, 4) as $n) {
        $subject = Subject::factory()->create(['department_id' => $this->world->department->id]);
        $career = $this->world->career("IC{$n}", null, $c2);

        foreach ([$this->world->campus, $c2] as $campus) {
            $this->world->groupsOf($this->world->request($career, 50, 2, subject: $subject, campus: $campus), 2);
            $this->world->request($this->isic, 20, 1, subject: $subject, campus: $campus);
        }
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(14);
});
