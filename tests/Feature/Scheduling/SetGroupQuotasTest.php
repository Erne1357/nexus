<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
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
| su departamento y la dueña se escriben directo; la de otro departamento se propone (acuerdo) y no cuenta hasta que
| su jefe lo acepta. La suma de los cupos no pasa la capacidad.
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
    return GroupQuota::query()->where('group_id', $group->id)->pluck('max_seats', 'career_id')->all();
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

it('propone a otro departamento sin darle cupo todavía', function () {
    setQuotas([$this->owner->id => 20, $this->foreign->id => 8]);

    $agreement = InterdepartmentalAgreement::query()->sole();
    expect(seatsOf($this->group))->toBe([$this->owner->id => 20])
        ->and($agreement->status)->toBe(AgreementStatus::Proposed)
        ->and($agreement->group_id)->toBe($this->group->id)
        ->and($agreement->career_id)->toBe($this->foreign->id)
        ->and($agreement->max_seats)->toBe(8)
        ->and($agreement->from_department_id)->toBe($this->imparte->id)
        ->and($agreement->to_department_id)->toBe($this->otro->id)
        ->and($agreement->requested_by)->toBe($this->actor->id);
});

it('guardar otra vez con la propuesta igual no la repite; con otros lugares la reemplaza', function () {
    setQuotas([$this->owner->id => 20, $this->foreign->id => 8]);
    setQuotas([$this->owner->id => 20, $this->foreign->id => 8]);
    expect(InterdepartmentalAgreement::query()->count())->toBe(1);

    setQuotas([$this->owner->id => 20, $this->foreign->id => 6]);
    $live = InterdepartmentalAgreement::query()->live()->sole();
    expect(InterdepartmentalAgreement::query()->count())->toBe(2)
        ->and($live->max_seats)->toBe(6);
});

it('cancela el acuerdo propuesto cuando la carrera deja de venir', function () {
    setQuotas([$this->owner->id => 20, $this->foreign->id => 8]);

    setQuotas([$this->owner->id => 20]);

    expect(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Cancelled);
});

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

it('HC-07: los propuestos no cuentan para la suma', function () {
    setQuotas([$this->owner->id => 40, $this->foreign->id => 30]);

    expect(seatsOf($this->group))->toBe([$this->owner->id => 40])
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(1);
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
