<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\DeleteProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\RenameProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\SetPackageCount;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalPackageSubjects;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalPackagesChanged;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Paquetes de primer semestre (tarea 5.6b; reglas-de-negocio.md, «Propuesta de carrera»): la composición es de toda la carrera,
| cada campus captura cuántos abre de cada paquete y cada paquete abre un grupo de cada una de sus materias. Las Actions
| recalculan `package_groups` y `requested_groups` de las filas afectadas, conservando los grupos sueltos.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->dept = Department::factory()->create(['code' => 'CB']);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    $this->proposal = CareerProposal::factory()->create(['career_id' => $this->career->id]);
    $this->subjectA = Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->dept->id]);
    $this->subjectB = Subject::factory()->create(['code' => 'AED-1285', 'department_id' => $this->dept->id]);
    $this->subjectC = Subject::factory()->create(['code' => 'SCC-1001', 'department_id' => Department::factory()->create(['code' => 'SC'])->id]);

    foreach ([$this->c1, $this->c2] as $campus) {
        ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $campus->id]);

        foreach ([$this->subjectA, $this->subjectB, $this->subjectC] as $subject) {
            ProposalItem::factory()->create([
                'career_proposal_id' => $this->proposal->id,
                'campus_id' => $campus->id,
                'subject_id' => $subject->id,
                'requested_groups' => 1,
            ]);
        }
    }
});

function packageItem(Subject $subject, Campus $campus): ProposalItem
{
    return ProposalItem::query()
        ->where('career_proposal_id', test()->proposal->id)
        ->where('subject_id', $subject->id)
        ->where('campus_id', $campus->id)
        ->sole();
}

function packageErrors(Closure $run): ?array
{
    try {
        $run();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

function newPackage(array $subjects, ?string $name = null): ProposalPackage
{
    return app(CreateProposalPackage::class)->handle(test()->proposal, array_map(fn (Subject $subject): string => $subject->id, $subjects), $name, test()->actor);
}

function packageCount(ProposalPackage $package, Campus $campus, int $count): ProposalPackageCampus
{
    return app(SetPackageCount::class)->handle($package, $campus, $count, test()->actor);
}

it('crea el paquete con el código siguiente, sus materias y una fila por columna', function () {
    Event::fake([ProposalPackagesChanged::class]);

    $first = newPackage([$this->subjectA, $this->subjectB], 'Matutino');
    $second = newPackage([$this->subjectC]);

    expect($first->code)->toBe('P1')
        ->and($first->name)->toBe('Matutino')
        ->and($first->position)->toBe(1)
        ->and($first->subjects->pluck('subject_id')->sort()->values()->all())->toBe(collect([$this->subjectA->id, $this->subjectB->id])->sort()->values()->all())
        ->and($first->campuses->pluck('campus_id')->sort()->values()->all())->toBe(collect([$this->c1->id, $this->c2->id])->sort()->values()->all())
        ->and($second->code)->toBe('P2')
        ->and($second->position)->toBe(2);

    $activity = Activity::query()->where('subject_id', $first->id)->sole();
    expect($activity->causer_id)->toBe($this->actor->id)->and($activity->description)->toBe('Armó el paquete P1 en la propuesta de ISC en Presencial');
    Event::assertDispatched(ProposalPackagesChanged::class, fn (ProposalPackagesChanged $event): bool => $event->packageId === $first->id && $event->proposalId === $this->proposal->id);
});

it('el código siguiente no repite el de un paquete borrado', function () {
    $first = newPackage([$this->subjectA]);
    $second = newPackage([$this->subjectB]);
    app(DeleteProposalPackage::class)->handle($first, $this->actor);

    expect(newPackage([$this->subjectC])->code)->toBe('P3')
        ->and($second->fresh()->code)->toBe('P2');
});

it('el primer paquete nace con los sugeridos que cubren el nuevo ingreso y los demás con 0', function () {
    ProposalIntake::query()->where('campus_id', $this->c1->id)->update(['new_students' => 120, 'suggested_new_students' => 120]);
    ProposalIntake::query()->where('campus_id', $this->c2->id)->update(['new_students' => 35, 'suggested_new_students' => 35]);

    $first = newPackage([$this->subjectA]);
    $second = newPackage([$this->subjectB]);

    $counts = fn (ProposalPackage $package): array => $package->campuses()->get()->mapWithKeys(fn (ProposalPackageCampus $column): array => [$column->campus_id => [$column->suggested_count, $column->count]])->all();

    expect($counts($first))->toBe([$this->c1->id => [4, 4], $this->c2->id => [1, 1]])
        ->and($counts($second))->toBe([$this->c1->id => [0, 0], $this->c2->id => [0, 0]])
        ->and(packageItem($this->subjectA, $this->c1)->package_groups)->toBe(4);
});

it('cualquier coordinador de la carrera arma la composición, también el de otro campus', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(21)])->create();

    expect($coordinador->can('managePackages', $this->proposal))->toBeTrue()
        ->and($other->can('managePackages', $this->proposal))->toBeFalse();
});

it('M7: un coordinador con campus solo cambia las cuentas y el nuevo ingreso de su campus', function () {
    $ofC2 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $ofAll = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect($ofC2->can('updateColumn', [$this->proposal, $this->c2]))->toBeTrue()
        ->and($ofC2->can('updateColumn', [$this->proposal, $this->c1]))->toBeFalse()
        ->and($ofC2->can('updateColumn', [$this->proposal, null]))->toBeFalse()
        ->and($ofAll->can('updateColumn', [$this->proposal, $this->c1]))->toBeTrue()
        ->and($ofAll->can('updateColumn', [$this->proposal, null]))->toBeTrue()
        ->and($subdireccion->can('updateColumn', [$this->proposal, $this->c1]))->toBeFalse();
});

it('rechaza un paquete sin materias, con una que no está en la propuesta o con un nombre largo', function () {
    $stranger = Subject::factory()->create(['code' => 'XXX-0000']);

    expect(packageErrors(fn () => newPackage([])))->toHaveKey('subjects')
        ->and(packageErrors(fn () => newPackage([$stranger]))['subjects'][0])->toContain('XXX-0000')
        ->and(packageErrors(fn () => newPackage([$this->subjectA], str_repeat('x', 61))))->toHaveKey('name')
        ->and(ProposalPackage::query()->count())->toBe(0);
});

it('una propuesta enviada o aceptada rechaza cualquier cambio de paquetes, una regresada no', function (ProposalStatus $status, bool $rejects) {
    $package = newPackage([$this->subjectA]);
    $this->proposal->forceFill(['status' => $status])->save();

    $errors = [
        packageErrors(fn () => newPackage([$this->subjectB])),
        packageErrors(fn () => app(UpdateProposalPackageSubjects::class)->handle($package, [$this->subjectB->id], $this->actor)),
        packageErrors(fn () => app(RenameProposalPackage::class)->handle($package, 'Nuevo', $this->actor)),
        packageErrors(fn () => packageCount($package, $this->c1, 2)),
        packageErrors(fn () => app(DeleteProposalPackage::class)->handle($package, $this->actor)),
    ];

    expect(collect($errors)->every(fn (?array $e): bool => $e !== null && isset($e['status'])))->toBe($rejects);
})->with([
    'enviada' => [ProposalStatus::Submitted, true],
    'aceptada' => [ProposalStatus::Accepted, true],
    'regresada' => [ProposalStatus::Returned, false],
]);

it('la parte aceptada de un departamento rechaza armar, cambiar, contar y borrar con sus materias', function () {
    $package = newPackage([$this->subjectA, $this->subjectC]);
    $this->proposal->forceFill(['status' => ProposalStatus::Returned])->save();
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->dept->id]);

    $errors = [
        packageErrors(fn () => newPackage([$this->subjectB])),
        packageErrors(fn () => app(UpdateProposalPackageSubjects::class)->handle($package, [$this->subjectC->id], $this->actor)),
        packageErrors(fn () => packageCount($package, $this->c1, 3)),
        packageErrors(fn () => app(DeleteProposalPackage::class)->handle($package, $this->actor)),
    ];

    expect(collect($errors)->every(fn (?array $e): bool => $e !== null && str_contains($e['package'][0], 'CB ya aceptó su parte')))->toBeTrue()
        ->and(ProposalPackage::query()->count())->toBe(1)
        ->and(packageItem($this->subjectC, $this->c1)->package_groups)->toBe(0);

    $ofOtherDepartment = newPackage([$this->subjectC]);
    expect($ofOtherDepartment->code)->toBe('P2');
});

it('la cuenta del campus recalcula package_groups y requested_groups, y conserva los sueltos', function () {
    $package = newPackage([$this->subjectA, $this->subjectB]);
    $item = packageItem($this->subjectA, $this->c1);
    $item->update(['requested_groups' => 3]);

    packageCount($package, $this->c1, 2);

    expect($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 5])
        ->and(packageItem($this->subjectB, $this->c1)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 3])
        ->and(packageItem($this->subjectA, $this->c2)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 0, 'requested_groups' => 1])
        ->and(packageItem($this->subjectC, $this->c1)->package_groups)->toBe(0);

    packageCount($package, $this->c1, 1);

    expect($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 1, 'requested_groups' => 4]);
});

it('la suma de varios paquetes con la misma materia es su package_groups', function () {
    $first = newPackage([$this->subjectA]);
    $second = newPackage([$this->subjectA, $this->subjectB]);

    packageCount($first, $this->c1, 2);
    packageCount($second, $this->c1, 3);

    expect(packageItem($this->subjectA, $this->c1)->package_groups)->toBe(5)
        ->and(packageItem($this->subjectB, $this->c1)->package_groups)->toBe(3);
});

it('marca la fila como corregida y deja bitácora de lo que cambió, sin tocar otra', function () {
    $package = newPackage([$this->subjectA]);

    packageCount($package, $this->c1, 2);

    $item = packageItem($this->subjectA, $this->c1);
    expect($item->captured_at)->not->toBeNull()
        ->and($item->captured_by)->toBe($this->actor->id)
        ->and(packageItem($this->subjectB, $this->c1)->captured_at)->toBeNull();

    $activity = Activity::query()->where('subject_id', $item->id)->sole();
    expect($activity->attribute_changes['attributes'])->toBe(['requested_groups' => 3, 'package_groups' => 2])
        ->and($activity->attribute_changes['old'])->toBe(['requested_groups' => 1, 'package_groups' => 0]);

    $column = Activity::query()->where('subject_id', $package->campuses()->where('campus_id', $this->c1->id)->value('id'))->sole();
    expect($column->attribute_changes['attributes'])->toBe(['count' => 2]);
});

it('SetPackageCount marca la cuenta como corregida, no escribe sin cambios y valida el rango', function () {
    $package = newPackage([$this->subjectA]);

    $column = packageCount($package, $this->c1, 2);
    expect($column->captured_at)->not->toBeNull()->and($column->captured_by)->toBe($this->actor->id);

    $before = Activity::query()->count();
    packageCount($package, $this->c1, 2);
    expect(Activity::query()->count())->toBe($before)
        ->and(packageErrors(fn () => packageCount($package, $this->c1, 31)))->toHaveKey('count')
        ->and(packageErrors(fn () => packageCount($package, $this->c1, -1)))->toHaveKey('count')
        ->and(packageErrors(fn () => app(SetPackageCount::class)->handle($package, null, 1, $this->actor)))->toHaveKey('campus');
});

it('rechaza una cuenta que pasaría del tope de grupos de la fila', function () {
    $package = newPackage([$this->subjectA]);
    packageItem($this->subjectA, $this->c1)->update(['requested_groups' => 5]);

    expect(packageErrors(fn () => packageCount($package, $this->c1, 28)))->toHaveKey('count')
        ->and($package->campuses()->where('campus_id', $this->c1->id)->value('count'))->toBe(0);
});

it('cambiar las materias recalcula las que salen y las que entran', function () {
    $package = newPackage([$this->subjectA]);
    packageCount($package, $this->c1, 2);

    app(UpdateProposalPackageSubjects::class)->handle($package, [$this->subjectB->id], $this->actor);

    expect(packageItem($this->subjectA, $this->c1)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 0, 'requested_groups' => 1])
        ->and(packageItem($this->subjectB, $this->c1)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 3])
        ->and($package->subjects()->pluck('subject_id')->all())->toBe([$this->subjectB->id]);
});

it('borrar el paquete lo quita de verdad y las filas conservan solo sus grupos sueltos', function () {
    $package = newPackage([$this->subjectA]);
    packageCount($package, $this->c1, 2);

    app(DeleteProposalPackage::class)->handle($package, $this->actor);

    expect(ProposalPackage::query()->count())->toBe(0)
        ->and(ProposalPackageCampus::query()->count())->toBe(0)
        ->and(packageItem($this->subjectA, $this->c1)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 0, 'requested_groups' => 1])
        ->and(Activity::query()->where('description', 'like', 'Borró el paquete P1%')->count())->toBe(1);
});

it('renombrar guarda el nombre, lo quita con vacío y no escribe sin cambios', function () {
    $package = newPackage([$this->subjectA]);

    app(RenameProposalPackage::class)->handle($package, '  Vespertino ', $this->actor);
    expect($package->fresh()->name)->toBe('Vespertino');

    $before = Activity::query()->count();
    app(RenameProposalPackage::class)->handle($package, 'Vespertino', $this->actor);
    app(RenameProposalPackage::class)->handle($package, '   ', $this->actor);

    expect($package->fresh()->name)->toBeNull()->and(Activity::query()->count())->toBe($before + 1);
});

it('UpdateProposalItem no deja bajar los grupos de los que salen de paquetes, pero sí los sueltos', function () {
    $package = newPackage([$this->subjectA]);
    packageItem($this->subjectA, $this->c1)->update(['requested_groups' => 3]);
    packageCount($package, $this->c1, 2);
    $item = packageItem($this->subjectA, $this->c1);

    $errors = packageErrors(fn () => app(UpdateProposalItem::class)->handle($item, 30, 1, null, $this->actor));

    expect($errors['requested_groups'][0])->toBe('2 grupos salen de paquetes; cambia los paquetes para bajarlos.')
        ->and($item->fresh()->requested_groups)->toBe(5);

    app(UpdateProposalItem::class)->handle($item, 30, 2, null, $this->actor);

    expect($item->fresh()->only(['requested_groups', 'package_groups']))->toBe(['requested_groups' => 2, 'package_groups' => 2]);
});
