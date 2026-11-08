<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AcceptSharedGroup (tarea 3.6, HC-07): Scheduling orquesta la aceptación de un acuerdo de grupo compartido: revisa
| que los lugares quepan, acepta el acuerdo y crea el cupo de la carrera, todo en una transacción.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->imparte = Department::factory()->create();
    $this->otro = Department::factory()->create();
    $this->foreign = Career::factory()->create(['department_id' => $this->otro->id]);
    $this->group = Group::factory()->withQuota(30)->create([
        'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
});

function proposedFor(int $seats = 8, AgreementStatus $status = AgreementStatus::Proposed): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->sharedGroup($seats)->inStatus($status)->create([
        'term_id' => test()->group->term_id, 'campus_id' => test()->group->campus_id, 'group_id' => test()->group->id,
        'from_department_id' => test()->imparte->id, 'to_department_id' => test()->otro->id, 'career_id' => test()->foreign->id,
    ]);
}

it('acepta el acuerdo y crea el cupo de la carrera con los lugares acordados', function () {
    $agreement = proposedFor(8);

    $quota = app(AcceptSharedGroup::class)->handle($agreement, $this->actor);

    expect($quota->group_id)->toBe($this->group->id)
        ->and($quota->career_id)->toBe($this->foreign->id)
        ->and($quota->max_seats)->toBe(8)
        ->and($quota->is_owner)->toBeFalse()
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Accepted)
        ->and($agreement->fresh()->decided_by)->toBe($this->actor->id);
});

it('HC-07: no acepta si los lugares ya no caben, sin dejar nada a medias', function () {
    $agreement = proposedFor(11);

    try {
        app(AcceptSharedGroup::class)->handle($agreement, $this->actor);
        $errors = null;
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toBe(['max_seats' => ['HC-07: el grupo ya tiene 30 de 40 lugares repartidos; caben 10.']])
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and(GroupQuota::query()->count())->toBe(1);
});

it('HC-07: cabe justo en la capacidad', function () {
    expect(app(AcceptSharedGroup::class)->handle(proposedFor(10), $this->actor)->max_seats)->toBe(10);
});

it('no acepta un acuerdo ya decidido, sin crear cupo', function () {
    $agreement = proposedFor(8, AgreementStatus::Rejected);

    expect(fn () => app(AcceptSharedGroup::class)->handle($agreement, $this->actor))->toThrow(ValidationException::class)
        ->and(GroupQuota::query()->count())->toBe(1);
});

it('deja bitácora en el grupo con el cupo nuevo', function () {
    app(AcceptSharedGroup::class)->handle(proposedFor(8), $this->actor);

    $activity = Activity::query()->where('event', 'quotas_updated')->sole();
    expect($activity->subject_id)->toBe($this->group->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->attribute_changes['attributes']['quotas'][$this->foreign->id])->toBe(8);
});

it('avisa con GroupQuotasChanged solo después del commit', function () {
    Event::fake([GroupQuotasChanged::class]);
    $agreement = proposedFor();

    try {
        DB::transaction(function () use ($agreement) {
            app(AcceptSharedGroup::class)->handle($agreement, $this->actor);
            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(GroupQuotasChanged::class);

    app(AcceptSharedGroup::class)->handle($agreement->fresh(), $this->actor);
    Event::assertDispatched(GroupQuotasChanged::class, fn (GroupQuotasChanged $event): bool => $event->group->is($this->group));
});
