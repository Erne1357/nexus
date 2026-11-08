<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Queries\GroupQuotasQuery;

/*
| GroupQuotasQuery (tarea 3.6): lo que muestra el panel de cupos de un grupo, sin que Blade consulte.
*/

beforeEach(function () {
    $this->imparte = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->otro = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->owner = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->imparte->id]);
    $this->group = Group::factory()->withQuota(20)->create([
        'capacity' => 40,
        'owner_career_id' => $this->owner->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
    $this->offer = function (Career $career, bool $active = true, ?string $campusId = null): Career {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campusId ?? $this->group->campus_id, 'is_active' => $active]);

        return $career;
    };
});

it('lista la dueña, los aceptados y los propuestos con su departamento, la capacidad y la suma', function () {
    $accepted = Career::factory()->create(['code' => 'IE', 'department_id' => $this->imparte->id]);
    $foreign = Career::factory()->create(['code' => 'IMEC', 'department_id' => $this->otro->id]);
    GroupQuota::factory()->create(['group_id' => $this->group->id, 'career_id' => $accepted->id, 'max_seats' => 10]);
    InterdepartmentalAgreement::factory()->sharedGroup(2)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->imparte->id, 'to_department_id' => $this->otro->id, 'career_id' => $foreign->id,
    ]);

    $data = app(GroupQuotasQuery::class)->for($this->group);

    expect($data['capacity'])->toBe(40)
        ->and($data['counted'])->toBe(30)
        ->and($data['proposed'])->toBe(2)
        ->and($data['department'])->toBe(['code' => 'CB', 'name' => 'Ciencias Básicas'])
        ->and(array_column($data['rows'], 'state'))->toBe(['owner', 'accepted', 'proposed'])
        ->and(array_column($data['rows'], 'code'))->toBe(['ISC', 'IE', 'IMEC'])
        ->and(array_column($data['rows'], 'seats'))->toBe([20, 10, 2])
        ->and($data['rows'][2]['decides'])->toBe(['code' => 'SIS', 'name' => 'Sistemas'])
        ->and($data['rows'][0]['decides'])->toBeNull();
});

it('los acuerdos rechazados o cancelados no aparecen', function (AgreementStatus $status) {
    $foreign = Career::factory()->create(['department_id' => $this->otro->id]);
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->imparte->id, 'to_department_id' => $this->otro->id, 'career_id' => $foreign->id,
    ]);

    expect(app(GroupQuotasQuery::class)->for($this->group)['rows'])->toHaveCount(1);
})->with([AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('ofrece las carreras activas del campus sin cupo ni acuerdo vivo, con si necesitan acuerdo', function () {
    $same = ($this->offer)(Career::factory()->create(['code' => 'AAA', 'department_id' => $this->imparte->id]));
    $foreign = ($this->offer)(Career::factory()->create(['code' => 'BBB', 'department_id' => $this->otro->id]));
    ($this->offer)(Career::factory()->create(['department_id' => $this->otro->id]), false);
    ($this->offer)(Career::factory()->create(['department_id' => $this->otro->id]), true, CareerCampus::factory()->create()->campus_id);
    Career::factory()->create(['department_id' => $this->otro->id]);
    ($this->offer)(Career::factory()->inactive()->create(['department_id' => $this->otro->id]));
    ($this->offer)($this->owner);
    $taken = ($this->offer)(Career::factory()->create(['department_id' => $this->imparte->id]));
    GroupQuota::factory()->create(['group_id' => $this->group->id, 'career_id' => $taken->id]);
    $proposed = ($this->offer)(Career::factory()->create(['department_id' => $this->otro->id]));
    InterdepartmentalAgreement::factory()->sharedGroup()->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->imparte->id, 'to_department_id' => $this->otro->id, 'career_id' => $proposed->id,
    ]);

    $addable = app(GroupQuotasQuery::class)->for($this->group)['addable'];

    expect(array_column($addable, 'code'))->toBe(['AAA', 'BBB'])
        ->and(array_column($addable, 'needs_agreement'))->toBe([false, true])
        ->and($addable[1])->toMatchArray(['id' => $foreign->id, 'department' => ['code' => $this->otro->code, 'name' => $this->otro->name]]);
});
