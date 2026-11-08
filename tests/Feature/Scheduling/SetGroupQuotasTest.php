<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\ProposeGroupQuota;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SetGroupQuotas (tarea 3.6, HC-07): el departamento que imparte reparte los lugares del grupo entre carreras. Las de
| su departamento y la dueña se escriben directo; la de otro departamento se propone (acuerdo) y aparta sus lugares
| desde la propuesta (confirmado el 8 de noviembre de 2026). La suma de los cupos y lo apartado no pasa la capacidad.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->imparte = Department::factory()->create(['code' => 'CB']);
    $this->otro = Department::factory()->create(['code' => 'SIS']);
    $this->owner = Career::factory()->create(['department_id' => $this->imparte->id]);
    $this->sameDept = Career::factory()->create(['department_id' => $this->imparte->id]);
    $this->foreign = Career::factory()->create(['department_id' => $this->otro->id]);
    $this->group = Group::factory()->withQuota(20)->create([
        'capacity' => 40,
        'owner_career_id' => $this->owner->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
    CareerCampus::factory()->create(['career_id' => $this->sameDept->id, 'campus_id' => $this->group->campus_id]);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'department_id' => $this->imparte->id]);
});

/**
 * @param  array<string, int>  $seats
 */
function setQuotas(array $seats): void
{
    app(SetGroupQuotas::class)->handle(test()->group->fresh(), $seats, test()->actor);
}

/**
 * @param  array<string, int>  $seats
 * @return array<string, list<string>>
 */
function setQuotasErrors(array $seats): array
{
    try {
        setQuotas($seats);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * @return array<string, int>
 */
function seatsOf(Group $group): array
{
    return GroupQuota::query()->where('group_id', $group->id)->orderByDesc('is_owner')->orderBy('id')->pluck('max_seats', 'career_id')->all();
}

it('crea, cambia y quita directo el cupo de una carrera del mismo departamento', function () {
    setQuotas([$this->owner->id => 20, $this->sameDept->id => 10]);
    expect(seatsOf($this->group))->toBe([$this->owner->id => 20, $this->sameDept->id => 10]);

    setQuotas([$this->owner->id => 25, $this->sameDept->id => 12]);
    expect(seatsOf($this->group))->toBe([$this->owner->id => 25, $this->sameDept->id => 12]);

    setQuotas([$this->owner->id => 25]);
    expect(seatsOf($this->group))->toBe([$this->owner->id => 25])
        ->and(GroupQuota::query()->where('is_owner', true)->count())->toBe(1);
});

it('cambia lugares de la dueña aunque sea de otro departamento', function () {
    $foreignOwner = Career::factory()->create(['department_id' => $this->otro->id]);
    $group = Group::factory()->withQuota(20)->create([
        'capacity' => 40, 'owner_career_id' => $foreignOwner->id,
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id,
        'subject_id' => $this->group->subject_id,
    ]);

    app(SetGroupQuotas::class)->handle($group, [$foreignOwner->id => 30], $this->actor);

    expect(seatsOf($group))->toBe([$foreignOwner->id => 30]);
});

it('la carrera dueña no se quita y lleva al menos un lugar', function () {
    expect(setQuotasErrors([$this->sameDept->id => 10]))->toHaveKey('quotas')
        ->and(setQuotasErrors([$this->owner->id => 0]))->toHaveKey('quotas.'.$this->owner->id)
        ->and(setQuotasErrors([$this->owner->id => 20, $this->sameDept->id => 1000]))->toHaveKey('quotas.'.$this->sameDept->id)
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
});

it('una carrera de otro departamento sin cupo no se agrega aquí: hay que proponerla primero', function () {
    expect(setQuotasErrors([$this->owner->id => 20, $this->foreign->id => 8]))
        ->toBe(['quotas.'.$this->foreign->id => ['Propón primero el cupo a '.$this->otro->name.'.']])
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0)
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
});

it('no toca las propuestas vivas de otras carreras', function () {
    InterdepartmentalAgreement::factory()->sharedGroup(8)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->imparte->id, 'to_department_id' => $this->otro->id, 'career_id' => $this->foreign->id,
    ]);

    setQuotas([$this->owner->id => 25]);

    expect(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Proposed);
});

it('una carrera nueva debe estar activa, sin borrar y ofrecida en el campus del grupo', function (Closure $prepare) {
    $career = Career::factory()->create(['department_id' => $this->imparte->id]);
    $prepare($career, $this->group);

    expect(setQuotasErrors([$this->owner->id => 20, $career->id => 5]))->toHaveKey('quotas.'.$career->id)
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
})->with([
    'no se ofrece' => [fn () => null],
    'oferta inactiva' => [fn (Career $career, Group $group) => CareerCampus::factory()->inactive()->create(['career_id' => $career->id, 'campus_id' => $group->campus_id])],
    'oferta en otro campus' => [fn (Career $career) => CareerCampus::factory()->create(['career_id' => $career->id])],
    'carrera inactiva' => [function (Career $career, Group $group) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $group->campus_id]);
        $career->update(['is_active' => false]);
    }],
    'carrera borrada' => [function (Career $career, Group $group) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $group->campus_id]);
        $career->delete();
    }],
]);

it('no edita cupos de un grupo cancelado ni con la carga fuera de proposed, staffing o adjusting', function (?LoadStatus $status, string $message) {
    if ($status !== null) {
        $this->load->update(['status' => $status]);
    } else {
        $this->group->update(['status' => GroupStatus::Cancelled]);
    }

    expect(setQuotasErrors([$this->owner->id => 25]))->toHaveKey('quotas')
        ->and(setQuotasErrors([$this->owner->id => 25])['quotas'][0])->toContain($message)
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
})->with([
    'cancelado' => [null, 'cancelado'],
    'borrador' => [LoadStatus::Draft, 'borrador'],
    'cerrada' => [LoadStatus::Closed, 'no se editan cupos.'],
    'publicada' => [LoadStatus::Published, 'no se editan cupos.'],
]);

it('no edita cupos si el departamento no tiene carga en el campus y el periodo (supuesto por confirmar)', function () {
    $this->load->delete();

    expect(setQuotasErrors([$this->owner->id => 25])['quotas'][0])
        ->toBe("{$this->imparte->name} no tiene carga en {$this->group->campus->name} en este periodo: no se editan cupos.")
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
});

it('edita cupos con la carga en proposed, staffing o adjusting', function (LoadStatus $status) {
    $this->load->update(['status' => $status]);

    setQuotas([$this->owner->id => 25]);

    expect(seatsOf($this->group))->toBe([$this->owner->id => 25]);
})->with([LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting]);

it('con cupo aceptado de otro departamento, cambiar lugares es directo y quitarlo lo borra y cancela su acuerdo', function () {
    GroupQuota::factory()->create(['group_id' => $this->group->id, 'career_id' => $this->foreign->id, 'max_seats' => 10]);
    $agreement = InterdepartmentalAgreement::factory()->sharedGroup(10)->inStatus(AgreementStatus::Accepted)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->imparte->id, 'to_department_id' => $this->otro->id, 'career_id' => $this->foreign->id,
    ]);

    setQuotas([$this->owner->id => 20, $this->foreign->id => 15]);
    expect(seatsOf($this->group)[$this->foreign->id])->toBe(15)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(1);

    setQuotas([$this->owner->id => 20]);
    expect(seatsOf($this->group))->toBe([$this->owner->id => 20])
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Cancelled);
});

it('HC-07: no deja que los cupos pasen la capacidad, sin escribir', function () {
    expect(setQuotasErrors([$this->owner->id => 30, $this->sameDept->id => 11]))
        ->toBe(['quotas' => ['HC-07: los cupos suman 41 y el grupo es de 40 lugares.']])
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
});

it('HC-07: los lugares propuestos quedan apartados desde la propuesta: los cupos no los toman (confirmado el 8 de noviembre de 2026)', function () {
    CareerCampus::factory()->create(['career_id' => $this->foreign->id, 'campus_id' => $this->group->campus_id]);
    app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 15, $this->actor);

    expect(setQuotasErrors([$this->owner->id => 20, $this->sameDept->id => 6]))
        ->toBe(['quotas' => ['HC-07: los cupos suman 26 y las propuestas apartan 15; el grupo es de 40 lugares. Quita 1 lugar.']])
        ->and(setQuotasErrors([$this->owner->id => 40]))
        ->toBe(['quotas' => ['HC-07: los cupos suman 40 y las propuestas apartan 15; el grupo es de 40 lugares. Quita 15 lugares.']])
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);

    setQuotas([$this->owner->id => 20, $this->sameDept->id => 5]);

    expect(seatsOf($this->group))->toBe([$this->owner->id => 20, $this->sameDept->id => 5])
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(1);
});

it('HC-07: al retirar, rechazar o cancelar la propuesta, sus lugares quedan libres otra vez', function (Closure $release) {
    CareerCampus::factory()->create(['career_id' => $this->foreign->id, 'campus_id' => $this->group->campus_id]);
    $agreement = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 15, $this->actor);
    expect(setQuotasErrors([$this->owner->id => 40]))->toHaveKey('quotas');

    $release($agreement, $this->actor);
    setQuotas([$this->owner->id => 40]);

    expect(seatsOf($this->group))->toBe([$this->owner->id => 40])
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(0);
})->with([
    'retirada' => [fn (InterdepartmentalAgreement $agreement, User $actor) => app(WithdrawGroupQuotaProposal::class)->handle($agreement, $actor)],
    'rechazada' => [fn (InterdepartmentalAgreement $agreement, User $actor) => app(RejectSharedGroup::class)->handle($agreement, 'Sin lugares.', $actor)],
    'cancelada' => [fn (InterdepartmentalAgreement $agreement, User $actor) => app(CancelAgreement::class)->handle($agreement, $actor)],
]);

it('HC-07: una propuesta aceptada deja de apartar y cuenta como su cupo, sin contarse dos veces', function () {
    CareerCampus::factory()->create(['career_id' => $this->foreign->id, 'campus_id' => $this->group->campus_id]);
    $agreement = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 15, $this->actor);
    app(AcceptSharedGroup::class)->handle($agreement, $this->actor);

    setQuotas([$this->owner->id => 25, $this->foreign->id => 15]);

    expect(seatsOf($this->group))->toBe([$this->owner->id => 25, $this->foreign->id => 15]);
});

it('HC-07: traduce el rechazo de la base al mismo mensaje', function () {
    // La base ve un cupo que la lectura previa no ve (otro proceso lo escribió): la suma real pasa la capacidad.
    $other = Career::factory()->create(['department_id' => $this->imparte->id]);
    GroupQuota::factory()->create(['group_id' => $this->group->id, 'career_id' => $other->id, 'max_seats' => 15]);
    GroupQuota::addGlobalScope('hide', fn ($query) => $query->where('is_owner', true));

    try {
        expect(setQuotasErrors([$this->owner->id => 20, $this->sameDept->id => 10]))
            ->toBe(['quotas' => ['HC-07: los cupos suman 45 y el grupo es de 40 lugares.']]);
    } finally {
        GroupQuota::clearBootedModels();
    }
});

it('deja bitácora en el grupo con los cupos antes y después', function () {
    setQuotas([$this->owner->id => 20, $this->sameDept->id => 10]);

    $activity = Activity::query()->where('event', 'quotas_updated')->sole();
    expect($activity->subject_id)->toBe($this->group->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->attribute_changes['old']['quotas'])->toBe([$this->owner->id => 20])
        ->and($activity->attribute_changes['attributes']['quotas'])->toBe([$this->owner->id => 20, $this->sameDept->id => 10]);
});

it('sin cambios no escribe bitácora ni avisa', function () {
    Event::fake([GroupQuotasChanged::class]);

    setQuotas([$this->owner->id => 20]);

    expect(Activity::query()->where('event', 'quotas_updated')->count())->toBe(0);
    Event::assertNotDispatched(GroupQuotasChanged::class);
});

it('avisa con GroupQuotasChanged solo después del commit', function () {
    Event::fake([GroupQuotasChanged::class]);

    try {
        DB::transaction(function () {
            setQuotas([$this->owner->id => 20, $this->sameDept->id => 10]);
            Event::assertNotDispatched(GroupQuotasChanged::class);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(GroupQuotasChanged::class);

    setQuotas([$this->owner->id => 20, $this->sameDept->id => 10]);
    Event::assertDispatched(GroupQuotasChanged::class, fn (GroupQuotasChanged $event): bool => $event->group->is($this->group));
});

it('rechaza una carrera que no existe, sin escribir', function () {
    $unknown = (string) Str::uuid7();

    expect(setQuotasErrors([$this->owner->id => 20, $unknown => 5]))->toHaveKey('quotas.'.$unknown)
        ->and(seatsOf($this->group))->toBe([$this->owner->id => 20]);
});
