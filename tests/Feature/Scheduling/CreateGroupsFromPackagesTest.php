<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\SetPackageCount;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\CreateGroupsFromProposal;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| CreateGroupsFromProposal con paquetes (tarea 5.6b; reglas-de-negocio.md, «Propuesta de carrera»): por cada paquete, campus
| y n de 1 a la cuenta, un grupo de cada materia del paquete con `package_id` y `package_number = n`; los grupos sueltos de la
| fila (`requested_groups` menos `package_groups`) nacen sin paquete. La cohorte del motor es `package:<id>:<n>` (fase 6).
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::factory()->create();
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->proposal = CareerProposal::factory()->create(['career_id' => $this->career->id]);
    $this->math = Subject::factory()->create(['department_id' => $this->cb->id]);
    $this->physics = Subject::factory()->create(['department_id' => $this->cb->id]);
    $this->code = Subject::factory()->create(['department_id' => $this->sc->id]);

    foreach ([$this->c1, $this->c2] as $campus) {
        ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $campus->id]);

        foreach ([$this->math, $this->physics, $this->code] as $subject) {
            ProposalItem::factory()->create([
                'career_proposal_id' => $this->proposal->id,
                'campus_id' => $campus->id,
                'subject_id' => $subject->id,
                'study_plan_id' => $this->plan->id,
                'requested_groups' => 0,
                'captured_at' => now(),
            ]);
        }
    }
});

function packagesPart(Department $department): ProposalReview
{
    return ProposalReview::factory()->accepted()->create(['career_proposal_id' => test()->proposal->id, 'department_id' => $department->id, 'decided_by' => test()->user->id]);
}

/**
 * @param  list<Subject>  $subjects
 * @param  array<string, int>  $counts  código de campus => cuenta
 */
function packagesOpen(array $subjects, array $counts): ProposalPackage
{
    $package = app(CreateProposalPackage::class)->handle(test()->proposal, array_map(fn (Subject $subject): string => $subject->id, $subjects), null, test()->user);

    foreach ($counts as $code => $count) {
        app(SetPackageCount::class)->handle($package, $code === 'C1' ? test()->c1 : test()->c2, $count, test()->user);
    }

    return $package;
}

/**
 * Los grupos por materia y campus, como «paquete:n» o «suelto», en orden.
 *
 * @return list<string>
 */
function packagesGroupsOf(Subject $subject, Campus $campus): array
{
    return Group::query()->where('subject_id', $subject->id)->where('campus_id', $campus->id)->orderBy('package_id')->orderBy('package_number')->get()
        ->map(fn (Group $group): string => $group->package_id === null ? 'suelto' : ProposalPackage::query()->find($group->package_id)->code.':'.$group->package_number)
        ->all();
}

it('abre un grupo de cada materia por cada paquete y campus, numerados de 1 a la cuenta', function () {
    packagesOpen([$this->math, $this->physics], ['C1' => 2, 'C2' => 1]);
    $part = packagesPart($this->cb);

    $created = app(CreateGroupsFromProposal::class)->handle($part, $this->user);

    expect($created)->toHaveCount(6)
        ->and(packagesGroupsOf($this->math, $this->c1))->toBe(['P1:1', 'P1:2'])
        ->and(packagesGroupsOf($this->math, $this->c2))->toBe(['P1:1'])
        ->and(packagesGroupsOf($this->physics, $this->c1))->toBe(['P1:1', 'P1:2'])
        ->and(Group::query()->where('subject_id', $this->code->id)->count())->toBe(0);

    $group = Group::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c1->id)->where('package_number', 2)->sole();
    expect($group->package->is(ProposalPackage::query()->sole()))->toBeTrue()
        ->and($group->campus_id)->toBe($this->c1->id);
});

it('los grupos sueltos de la fila nacen sin paquete', function () {
    packagesOpen([$this->math], ['C1' => 2]);
    ProposalItem::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c1->id)->update(['requested_groups' => 3]);

    app(CreateGroupsFromProposal::class)->handle(packagesPart($this->cb), $this->user);

    expect(packagesGroupsOf($this->math, $this->c1))->toBe(['P1:1', 'P1:2', 'suelto']);
});

it('una materia en dos paquetes abre los grupos de cada uno', function () {
    $first = packagesOpen([$this->math], ['C1' => 2]);
    $second = packagesOpen([$this->math, $this->physics], ['C1' => 1]);

    app(CreateGroupsFromProposal::class)->handle(packagesPart($this->cb), $this->user);

    expect(packagesGroupsOf($this->math, $this->c1))->toBe(['P1:1', 'P1:2', 'P2:1'])
        ->and(Group::query()->where('subject_id', $this->math->id)->pluck('package_id')->unique()->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('es idempotente: repetir no duplica grupos de paquete ni sueltos', function () {
    packagesOpen([$this->math], ['C1' => 2]);
    ProposalItem::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c1->id)->update(['requested_groups' => 3]);
    $part = packagesPart($this->cb);

    app(CreateGroupsFromProposal::class)->handle($part, $this->user);
    $again = app(CreateGroupsFromProposal::class)->handle($part, $this->user);

    expect($again)->toHaveCount(0)->and(Group::query()->count())->toBe(3);
});

it('sin paquetes las filas siguen abriendo solo grupos sueltos', function () {
    ProposalItem::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c2->id)->update(['requested_groups' => 2]);

    app(CreateGroupsFromProposal::class)->handle(packagesPart($this->cb), $this->user);

    expect(packagesGroupsOf($this->math, $this->c2))->toBe(['suelto', 'suelto']);
});

it('package_id y package_number van juntos y el número empieza en 1', function () {
    packagesOpen([$this->math], ['C1' => 1]);
    app(CreateGroupsFromProposal::class)->handle(packagesPart($this->cb), $this->user);
    $group = Group::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['package_number' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['package_number' => 0])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('groups')->where('id', $group->id)->update(['package_id' => null])))->toThrow(QueryException::class);
});

it('el total que nace de la fila es siempre requested_groups: package_groups de la fila manda sobre las cuentas', function () {
    $package = packagesOpen([$this->math], ['C1' => 2]);
    $second = packagesOpen([$this->physics], ['C2' => 2]);
    ProposalPackageCampus::query()->where('proposal_package_id', $package->id)->where('campus_id', $this->c1->id)->update(['count' => 5]);
    ProposalPackageCampus::query()->where('proposal_package_id', $second->id)->where('campus_id', $this->c2->id)->update(['count' => 1]);
    $part = packagesPart($this->cb);

    app(CreateGroupsFromProposal::class)->handle($part, $this->user);

    expect(packagesGroupsOf($this->math, $this->c1))->toBe(['P1:1', 'P1:2'])
        ->and(Group::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c1->id)->count())->toBe(ProposalItem::query()->where('subject_id', $this->math->id)->where('campus_id', $this->c1->id)->value('requested_groups'));

    expect(packagesGroupsOf($this->physics, $this->c2))->toBe(['P2:1', 'suelto']);
});
