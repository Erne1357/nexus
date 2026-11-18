<?php

use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Notifications\LoanCancelled;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Scheduling\ParallelsWorld;

/*
| CancelGroup (tarea 8.8; la fase 3 dejó el estado y el evento, no la Action): un grupo no se borra, se cancela. Pierde sus
| sesiones y sus acuerdos vivos; uno con docente no se cancela hasta quitárselo (supuesto por confirmar).
*/

beforeEach(function () {
    $this->world = ParallelsWorld::create();
    $this->actor = User::factory()->create();
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
