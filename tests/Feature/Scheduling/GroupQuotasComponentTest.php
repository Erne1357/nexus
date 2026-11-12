<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Panel «Cupos por carrera» del cajón del grupo (tarea 3.6, HC-07; maqueta `cajones`): lo edita el jefe del
| departamento que imparte (`scheduling.quota.manage`); el coordinador de la carrera dueña lo ve en solo lectura con
| el motivo; el jefe de otro departamento y otro campus no lo ven.
*/

const QUOTAS = 'scheduling::components.group-quotas';

beforeEach(function () {
    $this->imparte = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->otro = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->owner = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->imparte->id]);
    $this->sameDept = Career::factory()->create(['code' => 'IE', 'name' => 'Ingeniería Electrónica', 'department_id' => $this->imparte->id]);
    $this->foreign = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial', 'department_id' => $this->otro->id]);
    $this->group = Group::factory()->withQuota(20)->create([
        'capacity' => 40,
        'owner_career_id' => $this->owner->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->imparte->id])->id,
    ]);
    foreach ([$this->sameDept, $this->foreign] as $career) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->group->campus_id]);
    }
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->imparte->id])->create();
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'department_id' => $this->imparte->id]);
});

function quotasAs(User $user, Group $group): Testable
{
    return Livewire::actingAs($user)->test(QUOTAS, ['groupId' => $group->id]);
}

it('el jefe del departamento que imparte ve el panel con la dueña, la barra y «Guardar cupos»', function () {
    quotasAs($this->jefe, $this->group)
        ->assertOk()
        ->assertSee('Cupos por carrera')
        ->assertSee('ISC')
        ->assertSee('Dueña')
        ->assertSeeText('20 de 40 lugares repartidos')
        ->assertSee('Guardar cupos')
        ->assertSee('Agregar carrera')
        ->assertSee('una de otro departamento se propone en este momento a su jefe')
        ->set('newCareer', $this->sameDept->id)
        ->assertSee('Es del departamento que imparte: entra a los cupos y cuenta al «Guardar cupos».')
        ->set('newCareer', $this->foreign->id)
        ->assertSee('Proponer a Sistemas')
        ->assertSee('Es de otro departamento: se propone en este momento al jefe de Sistemas y sus lugares quedan apartados mientras decide.')
        ->assertDontSee('Los cupos los reparte');
});

it('guarda los cupos, avisa y despacha quotas-saved', function () {
    quotasAs($this->jefe, $this->group)
        ->set('newCareer', $this->sameDept->id)
        ->set('newSeats', 10)
        ->call('addCareer')
        ->assertSet('quotas.'.$this->sameDept->id, 10)
        ->set('quotas.'.$this->owner->id, 25)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('quotas-saved')
        ->assertSeeText('35 de 40 lugares repartidos');

    expect(GroupQuota::query()->where('group_id', $this->group->id)->orderByDesc('is_owner')->orderBy('id')->pluck('max_seats', 'career_id')->all())
        ->toBe([$this->owner->id => 25, $this->sameDept->id => 10]);
});

it('proponer a otra carrera de otro departamento crea el acuerdo y la muestra «Propuesto», con sus lugares apartados en la suma', function () {
    quotasAs($this->jefe, $this->group)
        ->set('newCareer', $this->foreign->id)
        ->set('newSeats', 5)
        ->call('addCareer')
        ->assertHasNoErrors()
        ->assertDispatched('quotas-saved')
        ->assertSee('Propuesto · aparta 5 lugares')
        ->assertSee('Espera al jefe de Sistemas')
        ->assertSeeText('25 de 40 lugares repartidos')
        ->assertSee('5 apartados por propuestas');

    expect(InterdepartmentalAgreement::query()->live()->sole()->career_id)->toBe($this->foreign->id)
        ->and(GroupQuota::query()->where('group_id', $this->group->id)->count())->toBe(1);
});

function proposedForeign(object $test, int $seats = 5): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->sharedGroup($seats)->create([
        'term_id' => $test->group->term_id, 'campus_id' => $test->group->campus_id, 'group_id' => $test->group->id,
        'from_department_id' => $test->imparte->id, 'to_department_id' => $test->otro->id, 'career_id' => $test->foreign->id,
    ]);
}

it('quitar un propuesto pide confirmar y retira su acuerdo al instante', function () {
    proposedForeign($this);

    $component = quotasAs($this->jefe, $this->group);
    $remove = domOf($component)->querySelector('[data-quota="IIND"] button[aria-label="Retirar la propuesta de IIND"]');

    expect($remove?->getAttribute('wire:confirm'))->toBeNull()
        ->and($remove?->getAttribute('data-confirm-title'))->toBe('¿Retirar la propuesta de IIND?')
        ->and($remove?->getAttribute('x-confirm'))->toBe('Se retira en este momento y el jefe de Sistemas ya no la verá.');

    $component
        ->call('removeCareer', $this->foreign->id)
        ->assertHasNoErrors()
        ->assertDispatched('quotas-saved')
        ->assertDontSee('Propuesto');

    expect(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Cancelled);
});

it('cambiar los lugares de un propuesto lo retira y lo propone de nuevo solo al pedirlo en su renglón', function () {
    $old = proposedForeign($this);

    quotasAs($this->jefe, $this->group)
        ->set('quotas.'.$this->foreign->id, 9)
        ->assertSee('Cambiar lugares')
        ->call('changeProposal', $this->foreign->id)
        ->assertHasNoErrors()
        ->assertDispatched('quotas-saved');

    $live = InterdepartmentalAgreement::query()->live()->sole();
    expect($old->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($live->max_seats)->toBe(9);
});

it('un borrador viejo no vuelve a proponer un acuerdo que ya no está vivo: pide proponerlo primero', function () {
    $agreement = proposedForeign($this);
    $component = quotasAs($this->jefe, $this->group);

    $agreement->update(['status' => AgreementStatus::Rejected, 'comment' => 'No', 'decided_by' => $this->jefe->id, 'decided_at' => now()]);
    $component->set('quotas.'.$this->owner->id, 22)->call('save')->assertHasErrors('quotas.'.$this->foreign->id);

    expect(InterdepartmentalAgreement::query()->live()->count())->toBe(0)
        ->and(GroupQuota::query()->sole()->max_seats)->toBe(20);
});

it('muestra arriba los errores de cualquier llave que regrese una Action', function () {
    $this->load->update(['status' => LoadStatus::Closed]);

    quotasAs($this->jefe, $this->group)
        ->set('newCareer', $this->foreign->id)
        ->set('newSeats', 4)
        ->call('addCareer')
        ->assertSeeHtml('data-testid="quotas-error"')
        ->assertSee('no se editan cupos.');
});

it('HC-07: si los lugares pasan la capacidad, el error sale junto a los cupos y no guarda', function () {
    quotasAs($this->jefe, $this->group)
        ->set('quotas.'.$this->owner->id, 41)
        ->assertSee('HC-07')
        ->call('save')
        ->assertHasErrors('quotas');

    expect(GroupQuota::query()->sole()->max_seats)->toBe(20);
});

it('HC-07: los lugares que aparta una propuesta cuentan para el error y no dejan guardar (confirmado el 8 de noviembre de 2026)', function () {
    proposedForeign($this, 15);

    quotasAs($this->jefe, $this->group)
        ->assertSee('Propuesto · aparta 15 lugares')
        ->assertSeeText('35 de 40 lugares repartidos')
        ->assertDontSeeHtml('data-testid="quotas-over"')
        ->set('quotas.'.$this->owner->id, 30)
        ->assertSeeHtml('data-testid="quotas-over"')
        ->assertSeeText('con los que apartan las propuestas')
        ->assertSeeText('Quita 5 lugares.')
        ->call('save')
        ->assertHasErrors('quotas');

    expect(GroupQuota::query()->sole()->max_seats)->toBe(20);
});

it('HC-07: lo apartado son los lugares guardados de la propuesta: bajarlos en el borrador no libera nada hasta «Cambiar lugares»', function () {
    proposedForeign($this, 15);

    $component = quotasAs($this->jefe, $this->group)
        ->set('quotas.'.$this->foreign->id, 5)
        ->set('quotas.'.$this->owner->id, 30)
        ->assertSeeText('45 de 40 lugares repartidos')
        ->assertSeeText('Quita 5 lugares.')
        ->call('save')
        ->assertHasErrors('quotas');

    $component->set('quotas.'.$this->owner->id, 20)
        ->set('quotas.'.$this->foreign->id, 18)
        ->assertSeeText('35 de 40 lugares repartidos')
        ->call('changeProposal', $this->foreign->id)
        ->assertHasNoErrors()
        ->assertSeeText('38 de 40 lugares repartidos');
});

it('HC-07: subir en el borrador los lugares de una propuesta no cuenta hasta «Cambiar lugares», así que no impide guardar lo que sí cabe', function () {
    proposedForeign($this, 15);

    quotasAs($this->jefe, $this->group)
        ->set('quotas.'.$this->foreign->id, 18)
        ->set('quotas.'.$this->owner->id, 25)
        ->assertSeeText('40 de 40 lugares repartidos')
        ->assertDontSeeHtml('data-testid="quotas-over"')
        ->call('save')
        ->assertHasNoErrors();

    expect(GroupQuota::query()->sole()->max_seats)->toBe(25)
        ->and(InterdepartmentalAgreement::query()->live()->sole()->max_seats)->toBe(15);
});

it('valida que los lugares sean enteros de 1 en adelante', function (mixed $value) {
    quotasAs($this->jefe, $this->group)
        ->set('quotas.'.$this->owner->id, $value)
        ->call('save')
        ->assertHasErrors('quotas.'.$this->owner->id);
})->with(['', 'abc', '0', '2.5']);

it('la dueña no se quita', function () {
    quotasAs($this->jefe, $this->group)
        ->call('removeCareer', $this->owner->id)
        ->assertSet('quotas.'.$this->owner->id, 20);
});

it('el coordinador de la carrera dueña lo ve en solo lectura con el motivo', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->group->campus_id, 'career_id' => $this->owner->id])->create();

    quotasAs($coordinador, $this->group)
        ->assertOk()
        ->assertSee('ISC')
        ->assertSeeText('20 de 40 lugares repartidos')
        ->assertSee('Los cupos los reparte el jefe de Ciencias Básicas.')
        ->call('save')
        ->assertForbidden();

    expect(GroupQuota::query()->sole()->max_seats)->toBe(20);
});

it('el jefe de otro departamento no ve el grupo ni guarda', function () {
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->otro->id])->create();

    quotasAs($jefeOtro, $this->group)->assertForbidden();
});

it('un jefe que pierde el permiso después de montar recibe 403 al guardar o agregar', function () {
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->otro->id])->create();
    $saving = quotasAs($this->jefe, $this->group);
    $adding = quotasAs($this->jefe, $this->group)->set('newCareer', $this->sameDept->id)->set('newSeats', 3);

    $this->actingAs($jefeOtro);

    $saving->call('save')->assertForbidden();
    $adding->call('addCareer')->assertForbidden();
    expect(GroupQuota::query()->count())->toBe(1);
});

it('AZ-S17: un grupo de otra modalidad no lo ve el coordinador de su carrera', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('EAD')->id, 'career_id' => $this->owner->id])->create();

    quotasAs($coordinador, $this->group)->assertForbidden();
});
