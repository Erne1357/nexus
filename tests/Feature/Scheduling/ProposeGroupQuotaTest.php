<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Quotas\ProposeGroupQuota;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| ProposeGroupQuota y WithdrawGroupQuotaProposal (tarea 3.6, HC-07): el departamento que imparte propone el cupo de
| una carrera de otro departamento y lo retira. Proponer no da cupo, pero aparta sus lugares desde ese momento
| (confirmado el 8 de noviembre de 2026); no se edita un grupo cancelado ni con la carga fuera de proposed, staffing
| o adjusting.
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
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->group->term_id, 'modality_id' => $this->group->modality_id, 'department_id' => $this->imparte->id]);
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

it('el mensaje de la carga dice el departamento, la modalidad y el estado', function () {
    $this->load->update(['status' => LoadStatus::Closed]);

    $errors = errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 5, $this->actor));

    expect($errors['quotas'][0])->toBe("La carga de {$this->imparte->name} en {$this->group->modality->name} está en cerrada: no se editan cupos.");
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

it('HC-07: la propuesta aparta sus lugares: una nueva cabe solo junto a los cupos y las demás propuestas vivas (confirmado el 8 de noviembre de 2026)', function () {
    $second = Career::factory()->create(['department_id' => $this->otro->id]);
    CareerCampus::factory()->create(['career_id' => $second->id, 'campus_id' => $this->group->campus_id]);
    app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 15, $this->actor);

    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $second, 6, $this->actor)))
        ->toBe(['max_seats' => ['HC-07: el grupo ya tiene 35 de 40 lugares repartidos o apartados; caben 5.']])
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(1)
        ->and(app(ProposeGroupQuota::class)->handle($this->group, $second, 5, $this->actor)->max_seats)->toBe(5);
});

it('HC-07: una propuesta que no cabe en lo que dejan los cupos se rechaza antes de escribir: ni acuerdo, ni bitácora, ni aviso', function () {
    Event::fake([AgreementRequested::class]);
    $inserts = [];
    DB::listen(function ($query) use (&$inserts): void {
        if (str_starts_with($query->sql, 'insert into "interdepartmental_agreements"') || str_starts_with($query->sql, 'insert into "activity_log"')) {
            $inserts[] = $query->sql;
        }
    });

    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 21, $this->actor)))
        ->toBe(['max_seats' => ['HC-07: el grupo ya tiene 20 de 40 lugares repartidos o apartados; caben 20.']])
        ->and($inserts)->toBe([])
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
    Event::assertNotDispatched(AgreementRequested::class);
});

it('HC-07: al retirar la propuesta, sus lugares se pueden proponer otra vez', function () {
    $second = Career::factory()->create(['department_id' => $this->otro->id]);
    CareerCampus::factory()->create(['career_id' => $second->id, 'campus_id' => $this->group->campus_id]);
    $first = app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 20, $this->actor);
    expect(errorsOf(fn () => app(ProposeGroupQuota::class)->handle($this->group, $second, 20, $this->actor)))->toHaveKey('max_seats');

    app(WithdrawGroupQuotaProposal::class)->handle($first, $this->actor);

    expect(app(ProposeGroupQuota::class)->handle($this->group, $second, 20, $this->actor)->status)->toBe(AgreementStatus::Proposed);
});

it('HC-07: bloquea el grupo antes de sumar lo apartado, para que dos propuestas a la vez no quepan las dos', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(ProposeGroupQuota::class)->handle($this->group, $this->foreign, 8, $this->actor);

    $lock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "groups"') && str_contains($sql, 'for update'));
    $held = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "interdepartmental_agreements"') && str_contains($sql, 'sum('));

    expect($lock)->toBeInt()
        ->and($held)->toBeInt()
        ->and($lock)->toBeLessThan($held);
});
