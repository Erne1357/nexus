<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\MoveGroupToCampus;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| MoveGroupToCampus (M8, MS9): el grupo nace con campus y se puede cambiar mientras la carga esté abierta; exige la
| carga editable y ninguna sesión con salón (primero se quitan los salones), y ningún acuerdo de grupo compartido vivo
| (MS25). El campus nuevo está activo y ofrece la carrera dueña del grupo. Quién lo hace: GroupManage con el campus de
| ahora y con el nuevo (MS6).
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->create();
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create([
        'term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->department->id,
    ]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->c2->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->department->id])->id,
        'owner_career_id' => $this->career->id,
        'study_plan_id' => StudyPlan::factory()->create(['career_id' => $this->career->id])->id,
        'code' => 'D',
    ]);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function moveGroup(?Campus $campus = null): Group
{
    return app(MoveGroupToCampus::class)->handle(test()->group, $campus ?? test()->c1);
}

it('M8: cambia el grupo de campus con sus sesiones, deja la bitácora y recalcula los choques de su modalidad', function () {
    Queue::fake([RecomputeScheduleConflicts::class]);
    $session = GroupSession::factory()->for($this->group)->on(1, '07:00', '09:00')->create();

    $moved = moveGroup();

    $log = Activity::query()->where('subject_id', $this->group->id)->where('description', 'like', 'Cambió el grupo %')->sole();

    expect($moved->campus_id)->toBe($this->c1->id)
        ->and($this->group->fresh()->campus_id)->toBe($this->c1->id)
        ->and($session->fresh()->campus_id)->toBe($this->c1->id)
        ->and($log->description)->toBe('Cambió el grupo D de Campus II a Campus I')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->attribute_changes->toArray())->toMatchArray(['attributes' => ['campus_id' => $this->c1->id], 'old' => ['campus_id' => $this->c2->id]]);
    Queue::assertPushed(RecomputeScheduleConflicts::class, fn (RecomputeScheduleConflicts $job): bool => $job->termId === $this->term->id && $job->modalityId === $this->pres->id);
});

it('M8: al mismo campus no escribe ni avisa', function () {
    Event::fake([GroupScheduleChanged::class]);

    moveGroup($this->c2);

    expect(Activity::query()->where('subject_id', $this->group->id)->exists())->toBeFalse();
    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('MS9: se rechaza si alguna de sus sesiones tiene salón', function () {
    $room = Room::factory()->create(['code' => 'LC-1', 'building_id' => Building::factory()->create(['campus_id' => $this->c2->id])->id]);
    GroupSession::factory()->for($this->group)->inRoom($room)->on(1, '07:00', '09:00')->create();

    expect(fn () => moveGroup())->toThrow(ValidationException::class, 'El grupo D tiene salón en 1 sesión: quítalo antes de cambiarlo de campus.')
        ->and($this->group->fresh()->campus_id)->toBe($this->c2->id);
});

it('MS9: se rechaza si la carga no está abierta', function () {
    $this->load->update(['status' => LoadStatus::Published]);

    expect(fn () => moveGroup())->toThrow(ValidationException::class, 'La carga de Sistemas y Computación en Presencial está en publicada: no se editan grupos.')
        ->and($this->group->fresh()->campus_id)->toBe($this->c2->id);
});

it('M8: se rechaza si el campus nuevo no ofrece la carrera o está inactivo', function () {
    CareerCampus::query()->where('campus_id', $this->c1->id)->update(['is_active' => false]);

    expect(fn () => moveGroup())->toThrow(ValidationException::class, 'Campus I no ofrece ISC: el grupo no se cambia ahí (HC-13).');

    CareerCampus::query()->where('campus_id', $this->c1->id)->update(['is_active' => true]);
    $this->c1->update(['is_active' => false]);

    expect(fn () => moveGroup())->toThrow(ValidationException::class, 'Campus I está inactivo: el grupo no se cambia ahí.')
        ->and($this->group->fresh()->campus_id)->toBe($this->c2->id);
});

it('M8, MS6: cambiar de campus pide gestionar el grupo en el campus de ahora y en el nuevo', function () {
    $c2Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $c1Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create();
    $both = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->career->id])->create();

    expect($c2Only->can('moveToCampus', [$this->group, $this->c1]))->toBeFalse()
        ->and($c1Only->can('moveToCampus', [$this->group, $this->c1]))->toBeFalse()
        ->and($both->can('moveToCampus', [$this->group, $this->c1]))->toBeTrue();
});

it('M8: el cajón del grupo lo cambia de campus y un coordinador limitado a su campus no puede', function () {
    $both = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->career->id])->create();
    $c2Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();

    Livewire::actingAs($c2Only)
        ->test('scheduling::components.group-drawer', ['termId' => $this->term->id, 'campusId' => $this->c2->id])
        ->call('open', $this->group->id)
        ->assertSet('show', true)
        ->assertDontSeeHtml('data-testid="group-campus"')
        ->set('targetCampus', $this->c1->code)
        ->call('moveToCampus')
        ->assertForbidden();

    Livewire::actingAs($both)
        ->test('scheduling::components.group-drawer', ['termId' => $this->term->id, 'campusId' => $this->c2->id])
        ->call('open', $this->group->id)
        ->assertSeeHtml('data-testid="group-campus"')
        ->set('targetCampus', $this->c1->code)
        ->call('moveToCampus')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('schedule-changed');

    expect($this->group->fresh()->campus_id)->toBe($this->c1->id);
});

it('MS25: se rechaza mientras el grupo tenga un acuerdo de grupo compartido propuesto o aceptado, y se permite si se rechazó o se canceló', function (AgreementStatus $status, bool $refused) {
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->c2->id, 'group_id' => $this->group->id, 'from_department_id' => $this->department->id,
    ]);

    if ($refused) {
        expect(fn () => moveGroup())->toThrow(ValidationException::class, 'El grupo D tiene un acuerdo de grupo compartido propuesto o aceptado: resuélvelo antes de cambiarlo de campus (MS25).')
            ->and($this->group->fresh()->campus_id)->toBe($this->c2->id);

        return;
    }

    expect(moveGroup()->campus_id)->toBe($this->c1->id);
})->with([
    'propuesto' => [AgreementStatus::Proposed, true],
    'aceptado' => [AgreementStatus::Accepted, true],
    'rechazado' => [AgreementStatus::Rejected, false],
    'cancelado' => [AgreementStatus::Cancelled, false],
]);

it('MS25: el cajón dice por qué no se cambia de campus antes del clic, con el botón deshabilitado, mientras el acuerdo siga vivo', function (AgreementStatus $status, bool $blocked) {
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->c2->id, 'group_id' => $this->group->id, 'from_department_id' => $this->department->id,
    ]);
    $drawer = Livewire::test('scheduling::components.group-drawer', ['termId' => $this->term->id, 'campusId' => $this->c2->id])->call('open', $this->group->id);

    if (! $blocked) {
        $drawer->assertDontSeeHtml('data-testid="group-campus-blocked"')->assertSeeHtml('data-testid="group-campus-move"');

        return;
    }

    $drawer->assertSeeHtml('data-testid="group-campus-blocked"')
        ->assertSee('Tiene un acuerdo de grupo compartido propuesto o aceptado: resuélvelo en Acuerdos antes de cambiarlo de campus.')
        ->assertSeeHtml('aria-describedby="group-campus-blocked"')
        ->assertDontSeeHtml('data-testid="group-campus-move"')
        ->assertDontSee('(MS25)')
        ->set('targetCampus', $this->c1->code)
        ->call('moveToCampus')
        ->assertHasErrors('campus');

    expect($this->group->fresh()->campus_id)->toBe($this->c2->id);
})->with([
    'propuesto' => [AgreementStatus::Proposed, true],
    'aceptado' => [AgreementStatus::Accepted, true],
    'rechazado' => [AgreementStatus::Rejected, false],
]);

it('MS25: un préstamo de salón vivo de otro grupo no detiene el cambio', function () {
    InterdepartmentalAgreement::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->c2->id]);

    expect(moveGroup()->campus_id)->toBe($this->c1->id);
});
