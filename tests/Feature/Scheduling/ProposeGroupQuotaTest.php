<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Quotas\ProposeGroupQuota;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Validation\ValidationException;

/*
| ProposeGroupQuota y WithdrawGroupQuotaProposal (tarea 3.6, HC-07): el departamento que imparte propone el cupo de
| una carrera de otro departamento y lo retira. Proponer no da cupo; no se edita un grupo cancelado ni con la carga
| fuera de proposed, staffing o adjusting.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->imparte = Department::factory()->create(['code' => 'CB']);
    $this->otro = Department::factory()->create(['code' => 'SIS']);
    $this->foreign = Career::factory()->create(['department_id' => $this->otro->id]);
    $this->group = Group::factory()->withQuota(20)->create([
        'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
    CareerCampus::factory()->create(['career_id' => $this->foreign->id, 'campus_id' => $this->group->campus_id]);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'department_id' => $this->imparte->id]);
});

function errorsOf(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('propone con el periodo, campus y departamento del grupo, sin darle cupo', function () {
    $agreement = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 8, $this->actor);

    expect($agreement->status)->toBe(AgreementStatus::Proposed)
        ->and($agreement->group_id)->toBe($this->group->id)
        ->and($agreement->term_id)->toBe($this->group->term_id)
        ->and($agreement->campus_id)->toBe($this->group->campus_id)
        ->and($agreement->from_department_id)->toBe($this->imparte->id)
        ->and($agreement->to_department_id)->toBe($this->otro->id)
        ->and($agreement->max_seats)->toBe(8)
        ->and(GroupQuota::query()->count())->toBe(1);
});

it('una carrera de su propio departamento no necesita acuerdo', function () {
    $own = Career::factory()->create(['department_id' => $this->imparte->id]);
    CareerCampus::factory()->create(['career_id' => $own->id, 'campus_id' => $this->group->campus_id]);

    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $own, 5, $this->actor)))->toHaveKey('career_id');
});

it('exige la carrera activa y ofrecida en el campus del grupo', function () {
    $other = Career::factory()->create(['department_id' => $this->otro->id]);

    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $other, 5, $this->actor)))->toHaveKey('quotas.'.$other->id)
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
});

it('no propone ni retira en un grupo cancelado o con la carga cerrada', function (?LoadStatus $status) {
    $agreement = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 8, $this->actor);
    $other = Career::factory()->create(['department_id' => $this->otro->id]);
    CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->group->campus_id]);

    if ($status === null) {
        $this->group->update(['status' => GroupStatus::Cancelled]);
    } else {
        $this->load->update(['status' => $status]);
    }

    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $other, 5, $this->actor)))->toHaveKey('quotas')
        ->and(errorsOf(fn () => app(WithdrawGroupQuotaProposal::class)->handle($agreement, $this->actor)))->toHaveKey('quotas')
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Proposed);
})->with([null, LoadStatus::Closed, LoadStatus::Published]);

it('el mensaje de la carga dice el departamento, el campus y el estado', function () {
    $this->load->update(['status' => LoadStatus::Closed]);

    $errors = errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 5, $this->actor));

    expect($errors['quotas'][0])->toBe("La carga de {$this->imparte->name} en {$this->group->campus->name} está en cerrada: no se editan cupos.");
});

it('retira la propuesta', function () {
    $agreement = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 8, $this->actor);

    app(WithdrawGroupQuotaProposal::class)->handle($agreement, $this->actor);

    expect($agreement->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($agreement->fresh()->cancelled_by)->toBe($this->actor->id);
});

it('no retira un acuerdo que no es de grupo compartido', function () {
    $loan = InterdepartmentalAgreement::factory()->create();

    expect(errorsOf(fn () => app(WithdrawGroupQuotaProposal::class)->handle($loan, $this->actor)))->toHaveKey('kind');
});
