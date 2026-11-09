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
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Database\Seeders\SchedulingAgreementsDemoSeeder;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Spatie\Activitylog\Models\Activity;

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

function seedSchedulingAgreements(array $clean = [], array $ratios = [], string $mode = 'mixed'): void
{
    app()->call([new SchedulingAgreementsDemoSeeder, 'run'], [
        'profile' => 'demo',
        'campuses' => ['C2'],
        'ratios' => [...['loans' => 10, 'shared' => 8, 'direct_quota_groups' => 0, 'suggested_rooms' => 1], ...$ratios],
        'cleanDepartments' => ['C2' => $clean],
        'mode' => $mode,
    ]);
}

function schedulingAgreementsByStatus(AgreementKind $kind): array
{
    return InterdepartmentalAgreement::query()->where('kind', $kind->value)->get()->countBy(fn (InterdepartmentalAgreement $agreement): string => $agreement->status->value)->all();
}

it('siembra préstamos de salón en los cuatro estados entre el departamento que imparte y el dueño de los salones', function () {
    seedSchedulingAgreements();

    $loans = schedulingAgreementsByStatus(AgreementKind::RoomLoan);

    expect($loans)->toHaveKeys([AgreementStatus::Accepted->value, AgreementStatus::Proposed->value, AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])
        ->and(array_sum($loans))->toBe(10)
        ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->pluck('from_department_id')->unique()->all())->toBe([$this->imparte->id])
        ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->pluck('to_department_id')->unique()->all())->toBe([$this->dueno->id]);
});

it('HC-10: los préstamos rechazados y cancelados dejaron la sesión sin salón; los aceptados y pendientes la conservan', function () {
    seedSchedulingAgreements();

    $withoutRoom = InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)
        ->whereIn('status', [AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])->get();
    $withRoom = InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)
        ->whereIn('status', [AgreementStatus::Accepted->value, AgreementStatus::Proposed->value])->get();

    expect($withoutRoom)->not->toBeEmpty()
        ->and($withoutRoom->every(fn (InterdepartmentalAgreement $loan): bool => GroupSession::query()->where('group_id', $loan->group_id)->where('day', $loan->day)->whereNotNull('room_id')->doesntExist()))->toBeTrue()
        ->and($withRoom->every(fn (InterdepartmentalAgreement $loan): bool => GroupSession::query()->where('group_id', $loan->group_id)->where('day', $loan->day)->where('room_id', $loan->room_id)->exists()))->toBeTrue();
});

it('siembra grupos compartidos en los cuatro estados y los aceptados crearon su cupo', function () {
    seedSchedulingAgreements();

    $shared = schedulingAgreementsByStatus(AgreementKind::SharedGroup);
    $accepted = InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup->value)->where('status', AgreementStatus::Accepted->value)->get();
    $notAccepted = InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup->value)->where('status', '!=', AgreementStatus::Accepted->value)->get();

    expect($shared)->toHaveKeys([AgreementStatus::Accepted->value, AgreementStatus::Proposed->value, AgreementStatus::Rejected->value, AgreementStatus::Cancelled->value])
        ->and(array_sum($shared))->toBeGreaterThanOrEqual(8)
        ->and($accepted->every(fn (InterdepartmentalAgreement $agreement): bool => GroupQuota::query()->where('group_id', $agreement->group_id)->where('career_id', $agreement->career_id)->where('max_seats', $agreement->max_seats)->exists()))->toBeTrue()
        ->and($notAccepted->every(fn (InterdepartmentalAgreement $agreement): bool => GroupQuota::query()->where('group_id', $agreement->group_id)->where('career_id', $agreement->career_id)->doesntExist()))->toBeTrue();
});

it('HC-07: ningún grupo reparte más lugares que su capacidad, y el grupo cancelado no se toca', function () {
    seedSchedulingAgreements();

    $over = Group::query()->get()->filter(fn (Group $group): bool => GroupQuota::query()->where('group_id', $group->id)->sum('max_seats') > $group->capacity);

    expect($over)->toBeEmpty()
        ->and(InterdepartmentalAgreement::query()->where('group_id', $this->cancelled->id)->exists())->toBeFalse()
        ->and(GroupQuota::query()->where('group_id', $this->cancelled->id)->count())->toBe(1);
});

it('asigna salones sugeridos a sesiones sin salón, de salones sin dueño', function () {
    seedSchedulingAgreements();

    $suggested = GroupSession::query()->whereNotNull('room_id')
        ->whereHas('room', fn ($query) => $query->whereNull('department_id'))->count();

    expect($suggested)->toBeGreaterThan(0);
});

it('no ensucia un departamento limpio: ni préstamos ni salones sugeridos, aunque una sesión ya esté en un salón ajeno', function () {
    $cleanGroup = Group::factory()->withQuota()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->limpio->id])->id,
    ]);
    $roomless = GroupSession::factory()->for($cleanGroup)->on(1, '15:00', '17:00')->create();
    $foreignRoom = Room::query()->where('department_id', $this->dueno->id)->firstOrFail();
    GroupSession::factory()->for($cleanGroup)->on(2, '15:00', '17:00')->create(['room_id' => $foreignRoom->id]);

    seedSchedulingAgreements([$this->limpio->code]);

    expect(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)
        ->where(fn ($query) => $query->where('from_department_id', $this->limpio->id)->orWhere('to_department_id', $this->limpio->id))->exists())->toBeFalse()
        ->and($roomless->fresh()->room_id)->toBeNull();
});

it('los salones sugeridos nunca piden préstamo ni rompen HC-05: sin salones libres propios, las sesiones se quedan sin salón', function () {
    Room::query()->whereNull('department_id')->update(['is_active' => false]);
    $building = Building::query()->firstOrFail();
    Room::factory()->capacity(10)->create(['building_id' => $building->id, 'department_id' => null]);

    seedSchedulingAgreements(ratios: ['loans' => 0, 'shared' => 0]);

    expect(InterdepartmentalAgreement::query()->count())->toBe(0)
        ->and(GroupSession::query()->whereNotNull('room_id')->count())->toBe(0);
});

it('es idempotente: una segunda corrida no agrega acuerdos', function () {
    seedSchedulingAgreements();
    $before = InterdepartmentalAgreement::query()->count();

    seedSchedulingAgreements();

    expect(InterdepartmentalAgreement::query()->count())->toBe($before)->and($before)->toBeGreaterThan(0);
});

it('se niega a correr en producción', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => seedSchedulingAgreements())->toThrow(RuntimeException::class);
});

describe('cupos completos (punto 5 de la demo)', function () {
    beforeEach(function () {
        $this->sameDepartment = Career::factory()->create(['department_id' => $this->imparte->id]);
        CareerCampus::factory()->create(['career_id' => $this->sameDepartment->id, 'campus_id' => $this->campus->id]);
    });

    it('acepta todos los grupos compartidos con su cupo, reparte cupos directos y no toca salones ni pide préstamos', function () {
        seedSchedulingAgreements(ratios: ['direct_quota_groups' => 2], mode: 'quotas');

        $shared = InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup->value)->get();

        expect($shared)->not->toBeEmpty()
            ->and($shared->every(fn (InterdepartmentalAgreement $agreement): bool => $agreement->status === AgreementStatus::Accepted))->toBeTrue()
            ->and($shared->every(fn (InterdepartmentalAgreement $agreement): bool => GroupQuota::query()->where('group_id', $agreement->group_id)->where('career_id', $agreement->career_id)->where('max_seats', $agreement->max_seats)->exists()))->toBeTrue()
            ->and(GroupQuota::query()->where('career_id', $this->sameDepartment->id)->count())->toBe(2)
            ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->exists())->toBeFalse()
            ->and(GroupSession::query()->whereNotNull('room_id')->exists())->toBeFalse();
    });

    it('HC-07: ningún grupo reparte más lugares que su capacidad', function () {
        seedSchedulingAgreements(ratios: ['direct_quota_groups' => 2], mode: 'quotas');

        expect(Group::query()->get()->filter(fn (Group $group): bool => GroupQuota::query()->where('group_id', $group->id)->sum('max_seats') > $group->capacity))->toBeEmpty();
    });

    it('volver a correrlo no agrega acuerdos ni cupos', function () {
        seedSchedulingAgreements(mode: 'quotas');
        $before = [InterdepartmentalAgreement::query()->count(), GroupQuota::query()->count()];

        seedSchedulingAgreements(mode: 'quotas');

        expect([InterdepartmentalAgreement::query()->count(), GroupQuota::query()->count()])->toBe($before);
    });

    it('se detiene si el campus no tiene grupos editables', function () {
        AcademicLoad::query()->update(['status' => LoadStatus::Draft]);

        expect(fn () => seedSchedulingAgreements(mode: 'quotas'))->toThrow(RuntimeException::class, 'no tiene grupos editables');
    });

    it('rechaza un modo desconocido', function () {
        expect(fn () => seedSchedulingAgreements(mode: 'todo'))->toThrow(InvalidArgumentException::class);
    });
});

describe('salones completos (punto 7 de la demo)', function () {
    beforeEach(function () {
        // Nueve grupos más el lunes de 7:00 a 9:00 junto al primero: diez sesiones a la vez y solo ocho salones sin
        // dueño, así que dos van a salones del otro departamento y piden préstamo.
        $this->crowd = collect(range(1, 9))->map(function (): Group {
            $group = Group::factory()->withQuota()->create([
                'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 40,
                'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
            ]);
            GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();

            return $group;
        });
    });

    it('da salón a toda sesión presencial sin choques duros y deja aceptados los préstamos que pidió (HC-02, HC-05, HC-10)', function () {
        $virtual = GroupSession::factory()->for($this->groups[1])->on(3, '18:00', '19:00')->virtual()->create();

        seedSchedulingAgreements(mode: 'rooms');

        $loans = InterdepartmentalAgreement::query()->where('kind', AgreementKind::RoomLoan->value)->get();
        $hard = ScheduleConflict::query()->whereIn('rule', ['HC-02', 'HC-05', 'HC-06', 'HC-10', 'HC-11'])->pluck('message')->all();

        expect(GroupSession::query()->whereKeyNot($virtual->id)->whereNull('room_id')->count())->toBe(0)
            ->and($virtual->fresh()->room_id)->toBeNull()
            ->and($loans)->toHaveCount(2)
            ->and($loans->every(fn (InterdepartmentalAgreement $loan): bool => $loan->status === AgreementStatus::Accepted && $loan->to_department_id === $this->dueno->id))->toBeTrue()
            ->and($hard)->toBe([]);
    });

    it('HC-06: la práctica de una materia con laboratorio va a un espacio de práctica; el salón por defecto de la materia va primero', function () {
        $building = Building::query()->firstOrFail();
        $lab = Room::factory()->ofType(RoomType::Laboratorio)->capacity(40)->create(['building_id' => $building->id]);
        $favorite = Room::query()->whereNull('department_id')->orderByDesc('code')->firstOrFail();
        $subject = Subject::factory()->create(['department_id' => $this->imparte->id, 'needs_lab' => true]);
        SubjectRoomDefault::factory()->create(['subject_id' => $subject->id, 'campus_id' => $this->campus->id, 'room_id' => $favorite->id, 'priority' => 1]);
        $group = Group::factory()->withQuota()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 30, 'subject_id' => $subject->id]);
        $theory = GroupSession::factory()->for($group)->on(4, '15:00', '16:00')->create();
        $practice = GroupSession::factory()->for($group)->on(5, '15:00', '17:00')->practica()->create();

        seedSchedulingAgreements(mode: 'rooms');

        expect($practice->fresh()->room_id)->toBe($lab->id)
            ->and($theory->fresh()->room_id)->toBe($favorite->id);
    });

    it('deja bitácora de una asignación por departamento con la Action, y las demás quedan escritas igual', function () {
        seedSchedulingAgreements(mode: 'rooms');

        expect(Activity::query()->where('event', AssignSessionRoom::EVENT)->count())->toBe(1)
            ->and(GroupSession::query()->whereNull('room_id')->count())->toBe(0);
    });

    it('si las preferencias dejan una sesión sin salón, reparte otra vez solo por el salón más justo', function () {
        $building = Building::query()->firstOrFail();
        $big = Room::factory()->capacity(60)->create(['building_id' => $building->id]);
        $medium = Room::factory()->capacity(50)->create(['building_id' => $building->id]);
        $subject = Subject::factory()->create(['department_id' => $this->imparte->id]);
        SubjectRoomDefault::factory()->create(['subject_id' => $subject->id, 'campus_id' => $this->campus->id, 'room_id' => $big->id, 'priority' => 1]);
        $prefersBig = Group::factory()->withQuota()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 50, 'subject_id' => $subject->id, 'code' => 'A1']);
        $needsBig = Group::factory()->withQuota()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 60, 'code' => 'Z9',
            'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id]);
        $first = GroupSession::factory()->for($prefersBig)->on(6, '07:00', '09:00')->create();
        $second = GroupSession::factory()->for($needsBig)->on(6, '07:00', '09:00')->create();

        seedSchedulingAgreements(mode: 'rooms');

        expect($first->fresh()->room_id)->toBe($medium->id)
            ->and($second->fresh()->room_id)->toBe($big->id);
    });

    it('se detiene sin escribir nada si una sesión no tiene salón que le sirva (HC-05)', function () {
        $this->crowd->first()->update(['capacity' => 60]);

        expect(fn () => seedSchedulingAgreements(mode: 'rooms'))->toThrow(RuntimeException::class, 'no tienen un salón libre')
            ->and(GroupSession::query()->whereNotNull('room_id')->exists())->toBeFalse();
    });

    it('dice qué sesiones se quedarían sin salón posible, sin escribir nada', function () {
        $seeder = new SchedulingAgreementsDemoSeeder;

        expect($seeder->missingRooms($this->term, $this->campus))->toBe([]);

        $this->crowd->first()->update(['capacity' => 60]);
        $missing = $seeder->missingRooms($this->term, $this->campus);

        expect($missing)->toHaveCount(1)
            ->and($missing[0])->toContain('el lunes de 7:00 a 9:00')
            ->and(GroupSession::query()->whereNotNull('room_id')->exists())->toBeFalse();
    });

    it('volver a correrlo no cambia salones ni pide más préstamos', function () {
        seedSchedulingAgreements(mode: 'rooms');
        $before = [GroupSession::query()->orderBy('id')->pluck('room_id', 'id')->all(), InterdepartmentalAgreement::query()->count()];

        seedSchedulingAgreements(mode: 'rooms');

        expect([GroupSession::query()->orderBy('id')->pluck('room_id', 'id')->all(), InterdepartmentalAgreement::query()->count()])->toBe($before);
    });
});
