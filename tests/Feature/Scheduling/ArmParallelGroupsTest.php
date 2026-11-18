<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use App\Modules\Scheduling\Actions\Parallels\ParallelArrangement;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\ParallelGroupsArranged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| ArmParallelGroups (tarea 8.8; reglas-de-negocio.md, «Paralelos»): el jefe del departamento que imparte junta lo que piden
| las carreras en menos grupos, sin junta ni acuerdo. Reorganiza los grupos sueltos que ya existen: se queda el de más
| avance, los que sobran se cancelan (nunca uno con docente) y los cupos de cada carrera nacen directos y en exclusiva,
| también los de otros departamentos (confirmado el 18 de noviembre de 2026). HC-07: no pasan la capacidad.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->actor = User::factory()->create();
    $this->isic = $this->world->career('ISIC');
    $this->iind = $this->world->career('IIND');
});

/**
 * @param  list<array<string, int>>  $arrangement  por grupo, clave de carrera => lugares
 */
function armParallels(array $arrangement): ParallelArrangement
{
    $ids = Career::query()->pluck('id', 'code')->all();

    return app(ArmParallelGroups::class)->handle(
        test()->world->term, test()->world->modality, test()->world->subject, test()->world->campus,
        array_map(fn (array $seats): array => collect($seats)->mapWithKeys(fn (int $count, string $code): array => [$ids[$code] => $count])->all(), $arrangement),
        test()->actor,
    );
}

/**
 * @param  list<array<string, int>>  $arrangement
 * @return array<string, list<string>>
 */
function armParallelsErrors(array $arrangement): array
{
    try {
        armParallels($arrangement);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * Una propuesta viva de grupo compartido de CB a la carrera en el grupo.
 */
function armParallelsProposal(Group $group, string $careerId, int $seats): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->sharedGroup($seats)->create([
        'term_id' => test()->world->term->id, 'modality_id' => test()->world->modality->id, 'group_id' => $group->id, 'career_id' => $careerId,
        'from_department_id' => test()->world->department->id, 'to_department_id' => Career::query()->findOrFail($careerId)->department_id,
    ]);
}

/**
 * Los cupos de un grupo con claves de carrera, la dueña primero.
 *
 * @return array<string, int>
 */
function armedSeats(Group $group): array
{
    return GroupQuota::query()->where('group_id', $group->id)->join('careers', 'careers.id', '=', 'group_quotas.career_id')
        ->orderByDesc('is_owner')->orderBy('careers.code')->pluck('max_seats', 'careers.code')->all();
}

it('junta 4 grupos de dos carreras en 3: se queda el de más avance, el mixto es de la carrera del que se queda y sobra uno', function () {
    [$isic1, $isic2] = $this->world->groupsOf($this->world->request($this->isic, 60, 2), 2);
    [$iind1, $iind2] = $this->world->groupsOf($this->world->request($this->iind, 40, 2), 2);
    $this->world->withTeacher($iind1);
    $session = $this->world->withSession($iind2, hours: 3);

    $result = armParallels([['IIND' => 35], ['ISIC' => 35], ['ISIC' => 25, 'IIND' => 5]]);

    expect(array_map(fn (array $row): string => $row['group']->code, $result->kept))->toBe(['3', '1', '4'])
        ->and(array_map(fn (array $row): string => $row['group']->code, $result->cancelled))->toBe(['2'])
        ->and($result->created)->toBe([])
        ->and(armedSeats($iind1))->toBe(['IIND' => 35])
        ->and(armedSeats($isic1))->toBe(['ISIC' => 35])
        ->and(armedSeats($iind2))->toBe(['IIND' => 5, 'ISIC' => 25])
        ->and($iind2->fresh()->owner_career_id)->toBe($this->iind->id)
        ->and($session->fresh())->not->toBeNull()
        ->and($isic2->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and($result->cancelled[0]['group']->status)->toBe(GroupStatus::Cancelled)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
});

it('un grupo con docente nunca se cancela: si el arreglo no le deja lugar, rechaza sin cambiar nada', function () {
    [$isic1] = $this->world->groupsOf($this->world->request($this->isic, 30, 1));
    [$iind1] = $this->world->groupsOf($this->world->request($this->iind, 5, 1));
    $this->world->withTeacher($isic1);
    $this->world->withTeacher($iind1);

    expect(armParallelsErrors([['ISIC' => 30, 'IIND' => 5]]))->toBe(['groups' => ['El grupo 2 de IIND tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de IIND o quítale primero el docente.']])
        ->and(Group::query()->where('status', GroupStatus::Cancelled)->count())->toBe(0)
        ->and(armedSeats($isic1))->toBe(['ISIC' => 35]);
});

it('HC-07: los cupos de un grupo no pasan su capacidad', function () {
    [$isic1] = $this->world->groupsOf($this->world->request($this->isic, 30, 1));
    $this->world->request($this->iind, 10, 1);

    expect(armParallelsErrors([['ISIC' => 30, 'IIND' => 10]]))->toBe(['groups.0' => ['HC-07: los cupos del grupo 1 suman 40 y es de 35 lugares.']])
        ->and(armedSeats($isic1))->toBe(['ISIC' => 35]);
});

it('con la carga fuera de propuesta, asignación o ajustes no arma', function () {
    $this->world->groupsOf($this->world->request($this->isic, 30, 1));
    $this->world->request($this->iind, 5, 1);
    $this->world->load->update(['status' => LoadStatus::Published]);

    expect(armParallelsErrors([['ISIC' => 30, 'IIND' => 5]]))->toBe(['groups' => ['La carga de Ciencias Básicas en Presencial está en publicada: no se editan paralelos.']]);
});

it('una carrera con su parte pendiente no se arma hasta aceptarla', function () {
    $this->world->groupsOf($this->world->request($this->isic, 30, 1));
    $this->world->request($this->iind, 5, 1, 'pending');

    expect(armParallelsErrors([['ISIC' => 30, 'IIND' => 5]]))->toBe(['groups' => ['IIND no tiene aceptada su parte con Cálculo Integral en C1: acéptala antes de armar.']])
        ->and(GroupQuota::query()->count())->toBe(1);
});

it('el grupo de paquete no se toca', function () {
    $item = $this->world->request($this->isic, 60, 2, packageGroups: 1);
    $package = ProposalPackage::factory()->create(['career_proposal_id' => $item->career_proposal_id]);
    [$packaged] = $this->world->groupsOf($item, 1, ['package_id' => $package->id, 'package_number' => 1]);
    [$loose] = $this->world->groupsOf($item);
    $this->world->request($this->iind, 5, 1);

    armParallels([['ISIC' => 25, 'IIND' => 5]]);

    expect(armedSeats($packaged))->toBe(['ISIC' => 35])
        ->and($packaged->fresh()->status)->toBe(GroupStatus::Planned)
        ->and(armedSeats($loose))->toBe(['ISIC' => 25, 'IIND' => 5]);
});

it('crea los grupos que faltan, sueltos y sin horario, de la carrera con más lugares', function () {
    Event::fake([GroupsCreated::class]);
    [$isic1] = $this->world->groupsOf($this->world->request($this->isic, 60, 2));
    $iindItem = $this->world->request($this->iind, 40, 2);

    $result = armParallels([['ISIC' => 35], ['IIND' => 35], ['IIND' => 5, 'ISIC' => 25]]);

    expect($result->created)->toHaveCount(2);
    [$iindFull, $mixed] = $result->created;

    expect([$iindFull->owner_career_id, $iindFull->proposal_item_id, $iindFull->capacity, $iindFull->package_id])->toBe([$this->iind->id, $iindItem->id, 35, null])
        ->and(armedSeats($iindFull))->toBe(['IIND' => 35])
        ->and($mixed->owner_career_id)->toBe($this->isic->id)
        ->and(armedSeats($mixed))->toBe(['ISIC' => 25, 'IIND' => 5])
        ->and([$iindFull->code, $mixed->code])->toBe(['2', '3'])
        ->and(GroupSession::query()->whereIn('group_id', [$iindFull->id, $mixed->id])->count())->toBe(0)
        ->and(armedSeats($isic1))->toBe(['ISIC' => 35]);

    Event::assertDispatched(GroupsCreated::class, fn (GroupsCreated $event): bool => $event->groupIds === [$iindFull->id, $mixed->id]);
});

it('deja bitácora por cada grupo tocado y dice qué horario se perdió al cancelar', function () {
    [$isic1, $isic2] = $this->world->groupsOf($this->world->request($this->isic, 30, 2), 2);
    $this->world->request($this->iind, 5, 1);
    $this->world->withSession($isic1, hours: 2);
    $this->world->withSession($isic2, day: 2, hours: 3);

    $result = armParallels([['ISIC' => 30, 'IIND' => 5]]);

    expect(array_map(fn (array $row): array => [$row['group']->code, $row['sessions'], $row['minutes']], $result->lostSchedules()))->toBe([['1', 1, 120]])
        ->and(GroupSession::query()->where('group_id', $isic1->id)->count())->toBe(0);

    $arranged = Activity::query()->where('event', 'parallels_arranged')->sole();
    $cancelled = Activity::query()->where('event', 'cancelled')->where('subject_id', $isic1->id)->sole();

    expect($arranged->subject_id)->toBe($isic2->id)
        ->and($arranged->causer_id)->toBe($this->actor->id)
        ->and($arranged->attribute_changes['old'])->toEqual(['quotas' => [$this->isic->id => 35]])
        ->and($arranged->attribute_changes['attributes'])->toEqual(['quotas' => [$this->isic->id => 30, $this->iind->id => 5]])
        ->and($cancelled->attribute_changes['old']['sessions'])->toBe([['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'teoria', 'room_id' => null]])
        ->and($cancelled->causer_id)->toBe($this->actor->id);
});

it('cancela las propuestas vivas de grupo compartido del grupo que se queda y escribe el cupo directo', function () {
    [$isic1] = $this->world->groupsOf($this->world->request($this->isic, 30, 1));
    $this->world->request($this->iind, 5, 1);
    $proposal = armParallelsProposal($isic1, $this->iind->id, 3);

    armParallels([['ISIC' => 30, 'IIND' => 5]]);

    expect($proposal->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and(armedSeats($isic1))->toBe(['ISIC' => 30, 'IIND' => 5]);
});

it('cancelar una propuesta sin tocar los cupos también es un cambio: deja bitácora y avisa a la carrera que la perdió', function () {
    [$isic1] = $this->world->groupsOf($this->world->request($this->isic, 35, 1));
    $this->world->request($this->iind, 5, 1);
    $proposal = armParallelsProposal($isic1, $this->iind->id, 3);
    $heard = [];
    Event::listen(ParallelGroupsArranged::class, function (ParallelGroupsArranged $event) use (&$heard): void {
        $heard[] = $event;
    });

    $result = armParallels([['ISIC' => 35]]);

    expect($result->changed())->toBeTrue()
        ->and($proposal->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and(armedSeats($isic1))->toBe(['ISIC' => 35])
        ->and(Activity::query()->where('event', 'parallels_arranged')->sole()->attribute_changes['old'])->toEqual(['quotas' => [$this->isic->id => 35], 'proposed' => [$this->iind->id => 3]])
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->careers)->toBe([$this->iind->id => ['before' => [['code' => '1', 'seats' => 3]], 'after' => [], 'group' => null]]);
});

it('un grupo nuevo no repite la clave del que se canceló en el mismo arreglo', function () {
    [$isic1, $isic2] = $this->world->groupsOf($this->world->request($this->isic, 35, 2), 2);
    $this->world->request($this->iind, 35, 1);

    $result = armParallels([['ISIC' => 35], ['IIND' => 35]]);

    expect($isic2->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and(array_map(fn (Group $group): string => $group->code, $result->created))->toBe(['3']);
});

it('avisa después del commit con lo que cambió para cada carrera, y sin cambios no avisa', function () {
    [$isic1, $isic2] = $this->world->groupsOf($this->world->request($this->isic, 30, 2), 2);
    $this->world->request($this->iind, 5, 1);
    $heard = [];
    Event::listen([ParallelGroupsArranged::class, GroupCancelled::class], function (object $event) use (&$heard): void {
        $heard[] = $event;
    });

    DB::transaction(function () use (&$heard): void {
        armParallels([['ISIC' => 30, 'IIND' => 5]]);
        expect($heard)->toBe([]);
    });

    [$cancelled, $arranged] = $heard;

    expect($heard)->toHaveCount(2)
        ->and($cancelled)->toBeInstanceOf(GroupCancelled::class)
        ->and($cancelled->group->is($isic2))->toBeTrue()
        ->and([$arranged->subjectId, $arranged->campusId, $arranged->departmentId])->toBe([$this->world->subject->id, $this->world->campus->id, $this->world->department->id])
        ->and($arranged->careers)->toBe([
            $this->isic->id => ['before' => [['code' => '1', 'seats' => 35], ['code' => '2', 'seats' => 35]], 'after' => [['code' => '1', 'seats' => 30]], 'group' => $isic1->id],
            $this->iind->id => ['before' => [], 'after' => [['code' => '1', 'seats' => 5]], 'group' => $isic1->id],
        ]);

    armParallels([['ISIC' => 30, 'IIND' => 5]]);

    expect($heard)->toHaveCount(2);
});

it('rechaza un arreglo vacío o con lugares fuera de rango', function () {
    $this->world->request($this->isic, 30, 1);

    expect(armParallelsErrors([]))->toBe(['groups' => ['El arreglo lleva de 1 a 60 grupos.']])
        ->and(armParallelsErrors([['ISIC' => 0]]))->toBe(['groups.0' => ['Los lugares de cada carrera van de 1 a 999.']]);
});
