<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Database\Seeders\SchedulingAgreementsDemoSeeder;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;

/*
| Acuerdos de prueba (tareas 3.6 y 3.7): un departamento que imparte con 16 grupos de 40 lugares y una sesión cada uno,
| otro departamento dueño de salones, uno más sin grupos, un tercero limpio y dos carreras ofrecidas en el campus; las
| cantidades se fuerzan para que cada estado de préstamo y de grupo compartido aparezca.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->imparte = Department::factory()->create();
    $this->dueno = Department::factory()->create();
    $this->limpio = Department::factory()->create();
    User::factory()->create(['username' => 'admin']);

    foreach ([$this->imparte, $this->limpio] as $department) {
        AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $department->id]);
    }

    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    Room::factory()->capacity(45)->count(10)->create(['building_id' => $building->id, 'department_id' => $this->dueno->id]);
    Room::factory()->capacity(45)->count(8)->create(['building_id' => $building->id, 'department_id' => null]);

    $this->careers = collect([$this->dueno, $this->limpio])->map(function (Department $department): Career {
        $career = Career::factory()->create(['department_id' => $department->id]);
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->campus->id]);

        return $career;
    });

    $owner = Career::factory()->create();
    $plan = StudyPlan::factory()->create(['career_id' => $owner->id]);
    $this->groups = collect(range(0, 15))->map(function (int $index) use ($owner, $plan): Group {
        $group = Group::factory()->withQuota()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 40, 'owner_career_id' => $owner->id, 'study_plan_id' => $plan->id,
            'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
        ]);
        GroupSession::factory()->for($group)->on(($index % 5) + 1, sprintf('%02d:00', 7 + intdiv($index, 5) * 2), sprintf('%02d:00', 9 + intdiv($index, 5) * 2))->create();

        return $group;
    });

    // Un grupo de otro departamento, ya cancelado, que no se debe tocar.
    $this->cancelled = Group::factory()->withQuota()->cancelled()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
});

function seedAgreements(array $clean = []): void
{
    app()->call([new SchedulingAgreementsDemoSeeder, 'run'], [
        'profile' => 'demo',
        'campuses' => ['C2'],
        'ratios' => ['loans' => 10, 'shared' => 8, 'direct_quota_groups' => 0, 'suggested_rooms' => 1],
        'cleanDepartments' => ['C2' => $clean],
    ]);
}

function agreementsOf(AgreementKind $kind): array
{
    return InterdepartmentalAgreement::query()->where('kind', $kind->value)->get()->countBy(fn (InterdepartmentalAgreement $agreement): string => $agreement->status->value)->all();
}

it('siembra préstamos de salón en los cuatro estados entre el departamento que imparte y el dueño de los salones', function () {
    seedAgreements();

    $loans = agreementsOf(AgreementKind::RoomLoan);

    expect($loans)->toHaveKeys([AgreementStatus::Accepted->value, AgreementStatus::Proposed->value, AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])
        ->and(array_sum($loans))->toBe(10)
        ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->pluck('from_department_id')->unique()->all())->toBe([$this->imparte->id])
        ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->pluck('to_department_id')->unique()->all())->toBe([$this->dueno->id]);
});

it('HC-10: los préstamos rechazados y cancelados dejaron la sesión sin salón; los aceptados y pendientes la conservan', function () {
    seedAgreements();

    $withoutRoom = InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)
        ->whereIn('status', [AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])->get();
    $withRoom = InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)
        ->whereIn('status', [AgreementStatus::Accepted->value, AgreementStatus::Proposed->value])->get();

    expect($withoutRoom)->not->toBeEmpty()
        ->and($withoutRoom->every(fn (InterdepartmentalAgreement $loan): bool => GroupSession::query()->where('group_id', $loan->group_id)->where('day', $loan->day)->whereNotNull('room_id')->doesntExist()))->toBeTrue()
        ->and($withRoom->every(fn (InterdepartmentalAgreement $loan): bool => GroupSession::query()->where('group_id', $loan->group_id)->where('day', $loan->day)->where('room_id', $loan->room_id)->exists()))->toBeTrue();
});

it('siembra grupos compartidos en los cuatro estados y los aceptados crearon su cupo', function () {
    seedAgreements();

    $shared = agreementsOf(AgreementKind::SharedGroup);
    $accepted = InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup->value)->where('status', AgreementStatus::Accepted->value)->get();
    $notAccepted = InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup->value)->where('status', '!=', AgreementStatus::Accepted->value)->get();

    expect($shared)->toHaveKeys([AgreementStatus::Accepted->value, AgreementStatus::Proposed->value, AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])
        ->and(array_sum($shared))->toBeGreaterThanOrEqual(8)
        ->and($accepted->every(fn (InterdepartmentalAgreement $agreement): bool => GroupQuota::query()->where('group_id', $agreement->group_id)->where('career_id', $agreement->career_id)->where('max_seats', $agreement->max_seats)->exists()))->toBeTrue()
        ->and($notAccepted->every(fn (InterdepartmentalAgreement $agreement): bool => GroupQuota::query()->where('group_id', $agreement->group_id)->where('career_id', $agreement->career_id)->doesntExist()))->toBeTrue();
});

it('HC-07: ningún grupo reparte más lugares que su capacidad, y el grupo cancelado no se toca', function () {
    seedAgreements();

    $over = Group::query()->get()->filter(fn (Group $group): bool => GroupQuota::query()->where('group_id', $group->id)->sum('max_seats') > $group->capacity);

    expect($over)->toBeEmpty()
        ->and(InterdepartmentalAgreement::query()->where('group_id', $this->cancelled->id)->exists())->toBeFalse()
        ->and(GroupQuota::query()->where('group_id', $this->cancelled->id)->count())->toBe(1);
});

it('asigna salones sugeridos a sesiones sin salón, de salones sin dueño', function () {
    seedAgreements();

    $suggested = GroupSession::query()->whereNotNull('room_id')
        ->whereHas('room', fn ($query) => $query->whereNull('department_id'))->count();

    expect($suggested)->toBeGreaterThan(0);
});

it('no ensucia un departamento limpio: sin salones ajenos ni préstamos, aunque sí le llegan cupos', function () {
    $cleanGroup = Group::factory()->withQuota()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->limpio->id])->id,
    ]);
    GroupSession::factory()->for($cleanGroup)->on(1, '15:00', '17:00')->create();

    seedAgreements([$this->limpio->code]);

    expect(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->where('group_id', $cleanGroup->id)->exists())->toBeFalse()
        ->and(GroupSession::query()->where('group_id', $cleanGroup->id)->whereNotNull('room_id')->exists())->toBeFalse();
});

it('es idempotente: una segunda corrida no agrega acuerdos', function () {
    seedAgreements();
    $before = InterdepartmentalAgreement::query()->count();

    seedAgreements();

    expect(InterdepartmentalAgreement::query()->count())->toBe($before)->and($before)->toBeGreaterThan(0);
});

it('se niega a correr en producción', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => seedAgreements())->toThrow(RuntimeException::class);
});
