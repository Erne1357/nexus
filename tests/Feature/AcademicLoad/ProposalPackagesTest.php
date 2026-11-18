<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\DeleteProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\RenameProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\SetPackageCount;
use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalIntakes;
use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalSuggestions;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalIntake;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalPackageSubjects;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
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
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
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
                'captured_at' => now(),
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

it('deja la bitácora de lo que cambió en la fila y en la cuenta, sin tocar otra', function () {
    $package = newPackage([$this->subjectA]);

    packageCount($package, $this->c1, 2);

    $item = packageItem($this->subjectA, $this->c1);
    $activity = Activity::query()->where('subject_id', $item->id)->sole();
    expect($activity->attribute_changes['attributes'])->toBe(['requested_groups' => 3, 'package_groups' => 2])
        ->and($activity->attribute_changes['old'])->toBe(['requested_groups' => 1, 'package_groups' => 0])
        ->and(Activity::query()->where('subject_id', packageItem($this->subjectB, $this->c1)->id)->count())->toBe(0);

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

/**
 * Una materia de primer semestre sin tocar, con la proyección de `$expected` esperados y `$suggested` grupos, en C1 con `$newStudents` de nuevo ingreso.
 */
function untouchedFirstSemesterRow(int $expected, int $suggested, int $newStudents, bool $lab = false, ?ForecastMethod $method = ForecastMethod::FirstSemester): ProposalItem
{
    $subject = Subject::factory()->create(['department_id' => test()->dept->id, 'needs_lab' => $lab]);
    ProposalIntake::query()->where('campus_id', test()->c1->id)->update(['new_students' => $newStudents, 'suggested_new_students' => $newStudents]);

    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => test()->c1->id,
        'subject_id' => $subject->id,
        'expected_students' => $expected,
        'requested_groups' => $suggested,
        'suggested_expected_students' => $expected,
        'suggested_groups' => $suggested,
        'forecast_method' => $method,
        'captured_at' => null,
    ]);
}

it('los paquetes cubren al nuevo ingreso: 140 de nuevo ingreso y 160 esperados son 4 paquetes y 1 suelto, sin doble conteo', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->count)->toBe(4)
        ->and($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 4, 'requested_groups' => 5])
        ->and($item->fresh()->captured_at)->toBeNull();
});

it('la fila sin tocar sigue sin tocar y los sueltos sugeridos usan 25 con laboratorio', function () {
    $item = untouchedFirstSemesterRow(60, 3, 30, lab: true);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->count)->toBe(1)
        ->and($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 1, 'requested_groups' => 3])
        ->and($item->fresh()->captured_at)->toBeNull();
});

it('una fila corregida conserva sus sueltos al cambiar los paquetes', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    $item->update(['captured_at' => now(), 'requested_groups' => 3]);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);

    expect($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 4, 'requested_groups' => 7]);
});

it('quitar el último paquete devuelve la fila sin tocar a los grupos sugeridos de la proyección', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);

    app(DeleteProposalPackage::class)->handle($package, $this->actor);

    expect($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 0, 'requested_groups' => 5]);
});

it('cambiar el nuevo ingreso recalcula las filas sin tocar con paquetes', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);

    app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 70, $this->actor);

    expect($item->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 5]);
});

it('la sincronización con la proyección mantiene la regla en las filas sin tocar con paquetes', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);
    DemandForecast::factory()->create([
        'term_id' => $this->proposal->term_id,
        'modality_id' => $this->proposal->modality_id,
        'career_id' => $this->career->id,
        'subject_id' => $item->subject_id,
        'campus_id' => $this->c1->id,
        'expected_students' => 200,
        'suggested_groups' => 6,
        'new_students' => 140,
        'method' => ForecastMethod::FirstSemester,
    ]);

    app(SyncProposalSuggestions::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);

    expect($item->fresh()->only(['expected_students', 'package_groups', 'requested_groups', 'suggested_groups']))->toBe(['expected_students' => 200, 'package_groups' => 4, 'requested_groups' => 6, 'suggested_groups' => 6])
        ->and($item->fresh()->captured_at)->toBeNull();
});

it('solo resta el nuevo ingreso en primer semestre: una materia de 2.º del paquete no lo resta', function () {
    $second = untouchedFirstSemesterRow(70, 2, 70, method: ForecastMethod::Formula);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$second->subject_id], null, $this->actor);

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->count)->toBe(2)
        ->and($second->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 4]);

    DemandForecast::factory()->create([
        'term_id' => $this->proposal->term_id, 'modality_id' => $this->proposal->modality_id, 'career_id' => $this->career->id,
        'subject_id' => $second->subject_id, 'campus_id' => $this->c1->id, 'expected_students' => 105, 'suggested_groups' => 3,
        'new_students' => null, 'method' => ForecastMethod::Formula,
    ]);
    app(SyncProposalSuggestions::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);

    expect($second->fresh()->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 5]);
});

it('una fila sin tocar con proyección se escribe una sola vez al recalcular, sin bitácora intermedia', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    $forecast = DemandForecast::factory()->create([
        'term_id' => $this->proposal->term_id, 'modality_id' => $this->proposal->modality_id, 'career_id' => $this->career->id,
        'subject_id' => $item->subject_id, 'campus_id' => $this->c1->id, 'expected_students' => 160, 'suggested_groups' => 5,
        'new_students' => 140, 'method' => ForecastMethod::FirstSemester,
    ]);
    app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);
    $before = Activity::query()->where('subject_id', $item->id)->count();

    $forecast->update(['new_students' => 70, 'expected_students' => 100, 'suggested_groups' => 3]);
    Event::fake([ProposalItemUpdated::class]);
    app(SyncProposalIntakes::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);
    app(SyncProposalSuggestions::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);

    expect($item->fresh()->only(['expected_students', 'package_groups', 'requested_groups']))->toBe(['expected_students' => 100, 'package_groups' => 2, 'requested_groups' => 3])
        ->and(Activity::query()->where('subject_id', $item->id)->count())->toBe($before);
    Event::assertNotDispatched(ProposalItemUpdated::class);
});

it('el tope de grupos no tumba la sincronización: recorta, deja bitácora y las demás propuestas siguen', function () {
    $big = untouchedFirstSemesterRow(1100, 4, 70, method: null);
    $package = app(CreateProposalPackage::class)->handle($this->proposal, [$big->subject_id], null, $this->actor);

    expect($big->fresh()->requested_groups)->toBe(30)
        ->and(Activity::query()->where('subject_id', $big->id)->latest('id')->first()->properties['capped_from'])->toBeGreaterThan(30);

    $otherProposal = CareerProposal::factory()->create(['career_id' => $this->career->id, 'term_id' => $this->proposal->term_id, 'modality_id' => modalityOf('EAD')->id]);
    ProposalIntake::factory()->create(['career_proposal_id' => $otherProposal->id, 'campus_id' => $this->c1->id]);
    ProposalIntake::query()->where('career_proposal_id', $this->proposal->id)->where('campus_id', $this->c1->id)->update(['new_students' => 1000, 'suggested_new_students' => 1000]);

    expect(fn () => app(SyncProposalIntakes::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]))->not->toThrow(ValidationException::class)
        ->and($big->fresh()->requested_groups)->toBeGreaterThanOrEqual($big->fresh()->package_groups);
});

/**
 * La fila de la proyección de una fila de la propuesta en C1.
 *
 * @param  array<string, mixed>  $attributes
 */
function packageForecastFor(ProposalItem $item, array $attributes): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => test()->proposal->term_id,
        'modality_id' => test()->proposal->modality_id,
        'career_id' => test()->career->id,
        'subject_id' => $item->subject_id,
        'campus_id' => test()->c1->id,
        ...$attributes,
    ]);
}

it('corregir el nuevo ingreso mueve los esperados de las filas sin tocar de 1.º: los de la proyección menos su nuevo ingreso más el corregido', function () {
    $first = untouchedFirstSemesterRow(160, 5, 140);
    packageForecastFor($first, ['expected_students' => 160, 'suggested_groups' => 5, 'new_students' => 140, 'method' => ForecastMethod::FirstSemester]);
    $captured = untouchedFirstSemesterRow(160, 5, 140);
    packageForecastFor($captured, ['expected_students' => 160, 'suggested_groups' => 5, 'new_students' => 140, 'method' => ForecastMethod::FirstSemester]);
    $captured->update(['expected_students' => 150, 'requested_groups' => 5, 'captured_at' => now()]);
    $second = untouchedFirstSemesterRow(70, 2, 140, method: ForecastMethod::Formula);
    packageForecastFor($second, ['expected_students' => 70, 'suggested_groups' => 2, 'new_students' => null, 'method' => ForecastMethod::Formula]);

    app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 70, $this->actor);

    // 160 − 140 + 70 = 90 esperados; sin paquetes, los grupos de la regla: 90 / 35 hacia arriba = 3. Lo sugerido no cambia.
    expect($first->fresh()->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))
        ->toBe(['expected_students' => 90, 'requested_groups' => 3, 'suggested_expected_students' => 160, 'suggested_groups' => 5])
        ->and($first->fresh()->captured_at)->toBeNull()
        ->and($captured->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 150, 'requested_groups' => 5])
        ->and($second->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 70, 'requested_groups' => 2]);

    // Con un paquete, los de paquetes cubren el nuevo ingreso corregido (2) y los sueltos, a los rezagados: 90 − 70 = 20, 1.
    app(CreateProposalPackage::class)->handle($this->proposal, [$first->subject_id], null, $this->actor);

    expect($first->fresh()->only(['expected_students', 'package_groups', 'requested_groups']))->toBe(['expected_students' => 90, 'package_groups' => 2, 'requested_groups' => 3]);

    // Al recalcular la proyección se conserva: el nuevo ingreso corregido sigue mandando.
    app(SyncProposalIntakes::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);
    app(SyncProposalSuggestions::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);

    expect($first->fresh()->only(['expected_students', 'package_groups', 'requested_groups']))->toBe(['expected_students' => 90, 'package_groups' => 2, 'requested_groups' => 3]);
});

it('la oferta nueva: con 0 de nuevo ingreso y su corrección a 35, las filas sin tocar de 1.º esperan 35', function () {
    $first = untouchedFirstSemesterRow(0, 0, 0);
    packageForecastFor($first, ['eligible_students' => 0, 'expected_students' => 0, 'suggested_groups' => 0, 'new_students' => 0, 'method' => ForecastMethod::FirstSemester]);

    app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 35, $this->actor);

    expect($first->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 35, 'requested_groups' => 1]);
});

it('una propuesta enviada no mueve sus filas: el nuevo ingreso no se corrige y la sincronización no la toca', function () {
    $first = untouchedFirstSemesterRow(160, 5, 140);
    packageForecastFor($first, ['expected_students' => 160, 'suggested_groups' => 5, 'new_students' => 140, 'method' => ForecastMethod::FirstSemester]);
    ProposalIntake::query()->where('career_proposal_id', $this->proposal->id)->where('campus_id', $this->c1->id)->update(['new_students' => 70, 'captured_at' => now()]);
    $this->proposal->forceFill(['status' => ProposalStatus::Submitted])->save();

    expect(packageErrors(fn () => app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 80, $this->actor)))->not->toBeNull();

    app(SyncProposalSuggestions::class)->handle($this->proposal->term_id, $this->proposal->modality_id, null, [$this->career->id]);

    expect($first->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 160, 'requested_groups' => 5]);
});

it('en una propuesta regresada, las filas del departamento que ya aceptó su parte no se mueven; las demás sí', function () {
    $accepted = untouchedFirstSemesterRow(160, 5, 140);
    $other = untouchedFirstSemesterRow(160, 5, 140);
    $other->subject->update(['department_id' => $this->subjectC->department_id]);
    foreach ([$accepted, $other] as $item) {
        packageForecastFor($item, ['expected_students' => 160, 'suggested_groups' => 5, 'new_students' => 140, 'method' => ForecastMethod::FirstSemester]);
    }
    $this->proposal->forceFill(['status' => ProposalStatus::Returned])->save();
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->dept->id]);

    app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 70, $this->actor);

    expect($accepted->fresh()->expected_students)->toBe(160)
        ->and($other->fresh()->expected_students)->toBe(90);
});

it('corregir el nuevo ingreso de la columna sin campus solo mueve esa columna, no las demás', function () {
    $intake = ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => null, 'new_students' => 140, 'suggested_new_students' => 140]);
    $noCampus = untouchedFirstSemesterRow(160, 5, 140);
    $noCampus->update(['campus_id' => null]);
    DemandForecast::factory()->create([
        'term_id' => $this->proposal->term_id, 'modality_id' => $this->proposal->modality_id, 'career_id' => $this->career->id,
        'subject_id' => $noCampus->subject_id, 'campus_id' => null, 'expected_students' => 160, 'suggested_groups' => 5,
        'new_students' => 140, 'method' => ForecastMethod::FirstSemester,
    ]);
    // En C1, la proyección ya no es la de la fila: una sincronización de todos los campus la movería.
    $onC1 = untouchedFirstSemesterRow(160, 5, 140);
    packageForecastFor($onC1, ['expected_students' => 200, 'suggested_groups' => 6, 'new_students' => 140, 'method' => ForecastMethod::FirstSemester]);

    app(UpdateProposalIntake::class)->handle($this->proposal, null, 70, $this->actor);

    expect($intake->fresh()->new_students)->toBe(70)
        ->and($noCampus->fresh()->expected_students)->toBe(90)
        ->and($onC1->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 160, 'requested_groups' => 5]);
});

it('registra el conteo viejo y el nuevo de cada columna que se mueve', function () {
    $item = untouchedFirstSemesterRow(160, 5, 140);
    app(CreateProposalPackage::class)->handle($this->proposal, [$item->subject_id], null, $this->actor);
    app(UpdateProposalIntake::class)->handle($this->proposal, $this->c1, 70, $this->actor);

    $entry = Activity::query()->where('subject_id', $this->proposal->id)->where('description', 'like', 'Se movieron%')->latest('id')->first();

    expect($entry->properties['moves'])->toBe([['package' => 'P1', 'campus_id' => $this->c1->id, 'old' => 4, 'new' => 2]])
        ->and($entry->attribute_changes['old'])->toBe(['package_counts' => [4]]);
});
