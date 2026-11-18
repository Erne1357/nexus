<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use App\Modules\Scheduling\Queries\ParallelDraft;
use App\Modules\Scheduling\Queries\ParallelsQuery;
use App\Modules\Scheduling\Queries\ParallelSubject;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| El armado que edita el jefe en el drawer de «Paralelos» (tarea 8.8, maqueta B): cuántos completos lleva cada carrera y
| cuántos esperados de cada una van a cada mixto. La vista previa coloca los grupos que ya existen con ParallelPlacement,
| como ArmParallelGroups, y dice qué no deja armar: la parte pendiente, el grupo con docente sin lugar y HC-07.
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->isic = $this->world->career('ISIC');
    $this->iind = $this->world->career('IIND');
});

/**
 * Arma con ArmParallelGroups, como el jefe de CB.
 *
 * @param  list<array<string, int>>  $arrangement
 */
function parallelDraftArm(array $arrangement): void
{
    $world = test()->world;
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $world->department->id])->create();

    app(ArmParallelGroups::class)->handle($world->term, $world->modality, $world->department, $world->subject, $world->campus, $arrangement, $jefe);
}

function parallelDraftRow(): ParallelSubject
{
    $world = test()->world;

    return app(ParallelsQuery::class)->for($world->term, $world->modality, $world->department)->subject($world->subject->id, $world->campus->id);
}

/**
 * El arreglo del armado con claves de carrera.
 *
 * @return list<array<string, int>>
 */
function parallelDraftCodes(ParallelDraft $draft): array
{
    $codes = Career::query()->pluck('code', 'id')->all();

    return array_map(fn (array $seats): array => collect($seats)->mapWithKeys(fn (int $count, string $id): array => [$codes[$id] => $count])->all(), $draft->arrangement());
}

it('parte de la sugerencia de Nexus: los completos de cada carrera y los mixtos con sus esperados en exclusiva', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);

    $draft = ParallelDraft::suggested(parallelDraftRow());

    expect($draft->full)->toBe([$this->iind->id => 1, $this->isic->id => 1])
        ->and($draft->mixed)->toBe([[$this->isic->id => 25, $this->iind->id => 5]])
        ->and(parallelDraftCodes($draft))->toBe([['IIND' => 35], ['ISIC' => 35], ['ISIC' => 25, 'IIND' => 5]])
        ->and($draft->left)->toBe([$this->iind->id => 0, $this->isic->id => 0])
        ->and($draft->saved())->toBe(1)
        ->and($draft->slots[2]->free())->toBe(5)
        ->and($draft->blockedReason())->toBeNull()
        ->and($draft->warning())->toBeNull();
});

it('al mover esperados recalcula el arreglo y dice cuántos quedan sin lugar o de más', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    $row = parallelDraftRow();

    $draft = ParallelDraft::of($row, [$this->isic->id => '1', $this->iind->id => 1], [[$this->isic->id => '20', $this->iind->id => '']]);

    expect(parallelDraftCodes($draft))->toBe([['IIND' => 35], ['ISIC' => 35], ['ISIC' => 35]])
        ->and($draft->left)->toBe([$this->iind->id => 5, $this->isic->id => 5])
        ->and($draft->warning())->toBe('5 de IIND y 5 de ISIC sin lugar: puedes armar así, pero esos alumnos no tendrán grupo.');

    $more = ParallelDraft::of($row, [$this->isic->id => 2, $this->iind->id => 1], [[$this->iind->id => 5]]);

    expect($more->left)->toBe([$this->iind->id => 0, $this->isic->id => -10])
        ->and($more->size())->toBe(4)
        ->and($more->saved())->toBe(0);
});

it('HC-07: un mixto cuyos cupos pasan su capacidad no se arma y dice cuál', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);

    $draft = ParallelDraft::of(parallelDraftRow(), [$this->isic->id => 1, $this->iind->id => 1], [[$this->isic->id => 30, $this->iind->id => 8]]);

    expect($draft->overs())->toBe([2])
        ->and($draft->slots[2]->over())->toBe(3)
        ->and($draft->slotName(2))->toBe('Mixto 1')
        ->and($draft->blockedReason())->toBe('Mixto 1 pasa su capacidad por 3 lugares (HC-07): quita esperados de ese grupo.');
});

it('la parte pendiente se ve pero no se arma hasta aceptarla', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2, 'pending');

    $draft = ParallelDraft::suggested(parallelDraftRow());

    expect($draft->blockedReason())->toBe('Falta aceptar la parte de IIND: sus lugares nacen al aceptarla.');

    $withoutIind = ParallelDraft::of(parallelDraftRow(), [$this->isic->id => 1], [[$this->isic->id => 25]]);

    expect($withoutIind->blockedReason())->toBeNull()
        ->and($withoutIind->left[$this->iind->id])->toBe(40);
});

it('coloca los grupos que ya existen como ArmParallelGroups y bloquea si uno con docente se quedaría sin lugar', function () {
    $isic = $this->world->request($this->isic, 30, 2);
    $this->world->request($this->iind, 5, 1);
    [$first, $second] = $this->world->groupsOf($isic, 2);
    $this->world->withSession($second, hours: 3);

    $draft = ParallelDraft::suggested(parallelDraftRow());

    expect(array_map(fn ($slot): ?string => $slot->keeps?->code, $draft->slots))->toBe(['2'])
        ->and(array_map(fn ($group): string => $group->code, $draft->cancelled))->toBe(['1'])
        ->and($draft->blocked)->toBe([])
        ->and($draft->blockedReason())->toBeNull();

    $this->world->withTeacher($first);
    $this->world->withTeacher($second);

    $blocked = ParallelDraft::suggested(parallelDraftRow());

    expect(array_map(fn ($group): string => $group->code, $blocked->blocked))->toBe(['1'])
        ->and($blocked->blockedReason())->toBe('El grupo 1 de ISIC tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de ISIC o quítale primero el docente.');
});

it('un grupo de una sola carrera lleva toda la capacidad del grupo que se queda', function () {
    $isic = $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    [$group] = $this->world->groupsOf($isic, 1, ['capacity' => 40]);

    $draft = ParallelDraft::suggested(parallelDraftRow());

    expect(parallelDraftCodes($draft))->toBe([['IIND' => 35], ['ISIC' => 40], ['ISIC' => 25, 'IIND' => 5]])
        ->and($draft->slots[1]->keeps?->code)->toBe($group->code)
        ->and($draft->slots[1]->capacity)->toBe(40);
});

it('ignora lo que no es un número, las carreras que no cuentan y las columnas vacías', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    $intruder = $this->world->career('IGEM');

    $draft = ParallelDraft::of(parallelDraftRow(), [$this->isic->id => 'dos', $intruder->id => 3, $this->iind->id => -4], [[], [$this->isic->id => 'x', $intruder->id => 9, $this->iind->id => 2000]]);

    expect($draft->full)->toBe([$this->iind->id => 0, $this->isic->id => 0])
        ->and($draft->mixed)->toBe([[], [$this->iind->id => 999]])
        ->and(parallelDraftCodes($draft))->toBe([['IIND' => 35]])
        ->and($draft->slotName(0))->toBe('Mixto 2');

    expect(ParallelDraft::of(parallelDraftRow(), [], [])->blockedReason())->toBe('Pon alumnos en al menos un grupo para armar.');
});

it('sabe si es la sugerencia de Nexus', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    $row = parallelDraftRow();

    expect(ParallelDraft::suggested($row)->isSuggestion())->toBeTrue()
        ->and(ParallelDraft::of($row, [$this->isic->id => 1, $this->iind->id => 1], [[$this->isic->id => 25, $this->iind->id => 5], []])->isSuggestion())->toBeTrue()
        ->and(ParallelDraft::of($row, [$this->isic->id => 1, $this->iind->id => 1], [[$this->isic->id => 24, $this->iind->id => 5]])->isSuggestion())->toBeFalse();
});

it('lo que muestra es lo que la Action acepta aunque dos grupos de la carrera tengan capacidades distintas', function () {
    $isic = $this->world->request($this->isic, 70, 2);
    $this->world->request($this->iind, 5, 1);
    [$small] = $this->world->groupsOf($isic, 1, ['capacity' => 30]);
    [$large] = $this->world->groupsOf($isic, 1, ['capacity' => 45]);
    $this->world->withTeacher($small);

    $draft = ParallelDraft::suggested(parallelDraftRow());

    expect($draft->overs())->toBe([])
        ->and($draft->blockedReason())->toBeNull()
        ->and(array_slice(parallelDraftCodes($draft), 0, 2))->toBe([['ISIC' => 30], ['ISIC' => 30]]);

    parallelDraftArm($draft->arrangement());

    expect($small->fresh()->quotas()->pluck('max_seats')->all())->toBe([30])
        ->and($large->fresh()->quotas()->pluck('max_seats')->all())->toBe([30]);
});

it('una materia armada se lee como está: sus completos y sus mixtos con sus cupos', function () {
    $this->world->request($this->isic, 60, 2);
    $this->world->request($this->iind, 40, 2);
    parallelDraftArm([[$this->iind->id => 35], [$this->isic->id => 35], [$this->isic->id => 22, $this->iind->id => 8]]);

    $draft = ParallelDraft::current(parallelDraftRow());

    expect($draft->full)->toBe([$this->iind->id => 1, $this->isic->id => 1])
        ->and($draft->mixed)->toBe([[$this->isic->id => 22, $this->iind->id => 8]])
        ->and($draft->cancelled)->toBe([])
        ->and($draft->isSuggestion())->toBeFalse();
});
