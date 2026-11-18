<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Notifications\LoanCancelled;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| CancelGroup (tarea 8.8; la fase 3 dejó el estado y el evento, no la Action): un grupo no se borra, se cancela. Pierde sus
| sesiones y sus acuerdos vivos; uno con docente no se cancela hasta quitárselo (supuesto por confirmar).
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->actor = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    [$this->group] = $this->world->groupsOf($this->world->request($this->world->career('ISIC'), 30, 1));
});

function cancelGroupErrors(Group $group): array
{
    try {
        app(CancelGroup::class)->handle($group, test()->actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('cancela el grupo, borra sus sesiones y cancela sus acuerdos vivos, y el dueño del salón recibe el aviso de siempre', function () {
    Notification::fake();
    $this->world->withSession($this->group, hours: 2);
    $loan = InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id, 'group_id' => $this->group->id,
        'from_department_id' => $this->world->department->id,
    ]);
    $owner = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $loan->to_department_id])->create();

    $result = app(CancelGroup::class)->handle($this->group, $this->actor);

    expect([$result['sessions'], $result['minutes'], $result['group']->status])->toBe([1, 120, GroupStatus::Cancelled])
        ->and($this->group->sessions()->count())->toBe(0)
        ->and($loan->fresh()->status)->toBe(AgreementStatus::Cancelled);

    Notification::assertSentTo($owner, LoanCancelled::class);
});

it('no cancela un grupo con una solicitud de cambio pendiente o aprobada', function () {
    ChangeRequest::factory()->forGroup($this->group)->create();

    expect(cancelGroupErrors($this->group))->toBe(['group' => ['Resuelve primero la solicitud de cambio de Cálculo Integral, grupo 1.']])
        ->and($this->group->fresh()->status)->toBe(GroupStatus::Planned);
});

it('no cancela un grupo con docente ni uno ya cancelado', function () {
    $this->world->withTeacher($this->group);

    expect(cancelGroupErrors($this->group))->toBe(['group' => ['El grupo 1 tiene docente: quítalo antes de cancelarlo.']]);

    $this->group->update(['teacher_id' => null, 'status' => GroupStatus::Cancelled]);

    expect(cancelGroupErrors($this->group))->toBe(['group' => ['El grupo 1 está cancelado: no se editan grupos.']]);
});

it('el jefe del departamento cancela un grupo suelto de su carga en cualquier campus de la modalidad', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $other = Group::factory()->withQuota()->create([
        'term_id' => $this->world->term->id, 'modality_id' => $this->world->modality->id, 'subject_id' => $this->world->subject->id, 'campus_id' => $c2->id,
    ]);

    expect(app(CancelGroup::class)->handle($other, $this->actor)['group']->status)->toBe(GroupStatus::Cancelled);
});

it('prueba negativa: sin permiso de cancelar el grupo no se cancela', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    $outsider = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sc->id])->create();

    expect(fn () => app(CancelGroup::class)->handle($this->group, $outsider))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelGroup::class)->handle($this->group, User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and($this->group->fresh()->status)->toBe(GroupStatus::Planned);
});
