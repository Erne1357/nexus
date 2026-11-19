<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestion;
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Scheduling\ReanalysisWorld;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Quitar docente» en Asignación docente (incidencia F-02 del ensayo 8.11): el jefe le quita el docente a un grupo con
| su motivo (UnassignTeacher; supuesto por confirmar), desde el renglón del grupo o el panel del grupo elegido, con un
| x-modal (ADR-0023). Quién puede lo decide GroupTeacherAssignmentPolicy, como asignar; el estado de la carga, la
| Action (asignación, ajustes o re-análisis). Sin docente, el grupo ya se puede cerrar o cancelar (CancelGroup).
*/

/**
 * @param  array<string, string>  $query
 */
function unassignPage(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.assign');
}

describe('quitar el docente', function () {
    beforeEach(function () {
        $this->world = AssignmentWorld::create();
        $isc = $this->world->career('ISC');
        $poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
        $this->group = $this->world->group($poo, $isc, 'G', 2, [[2, '13:00', '15:00']]);
        $this->teacher = $this->world->teacher('Beto Salas');
        $this->world->rank($this->group, [$this->teacher]);
        $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create(['name' => 'Rosa Villalobos']);
        $this->world->assign($this->group, $this->teacher, $this->jefe, position: 1, size: 1);
        onCampusPage($this->world->campus);
    });

    it('«Quitar» abre el modal con el docente y el grupo, y sin motivo no lo quita', function () {
        unassignPage($this->jefe, ['estado' => 'todos'])
            ->assertSeeHtml('data-testid="unassign-teacher"')
            ->call('askUnassign', $this->group->id)
            ->assertSet('showUnassign', true)
            ->assertSeeHtml('data-testid="unassign-modal"')
            ->assertSeeText('Quitar a Beto Salas del grupo G')
            ->call('unassign')
            ->assertHasErrors(['unassignReason' => 'required'])
            ->assertSet('showUnassign', true);

        expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
    });

    it('con el motivo quita al docente, deja la asignación como quitada con su motivo y avisa', function () {
        $page = unassignPage($this->jefe, ['estado' => 'todos'])
            ->call('askUnassign', $this->group->id)
            ->set('unassignReason', 'El grupo se cierra en el re-análisis.')
            ->call('unassign')
            ->assertHasNoErrors()
            ->assertSet('showUnassign', false)
            ->assertDontSeeHtml('data-testid="unassign-teacher"');

        expect($this->group->fresh()->teacher_id)->toBeNull()
            ->and(GroupTeacherAssignment::query()->sole()->replaced_by)->toBe($this->jefe->id)
            ->and(Activity::query()->where('event', 'teacher_unassigned')->sole()->properties['reason'])->toBe('El grupo se cierra en el re-análisis.')
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Quitaste a Beto Salas')
            ->and(lastToastOf($page)['description'])->toContain('G · Programación Orientada a Objetos queda sin docente');
    });

    it('en los dos paneles «Quitar docente» va junto al docente del grupo elegido', function () {
        unassignPage($this->jefe, ['vista' => 'paneles', 'grupo' => $this->group->id])
            ->assertSeeHtml('data-testid="unassign-teacher"')
            ->call('askUnassign', $this->group->id)
            ->set('unassignReason', 'Renunció al grupo.')
            ->call('unassign')
            ->assertHasNoErrors();

        expect($this->group->fresh()->teacher_id)->toBeNull();
    });

    it('quita al docente con la carga en re-análisis', function () {
        $this->world->load->update(['status' => LoadStatus::Reanalysis]);

        unassignPage($this->jefe, ['estado' => 'todos'])
            ->assertSeeHtml('data-testid="unassign-teacher"')
            ->call('askUnassign', $this->group->id)
            ->set('unassignReason', 'Sobra en el re-análisis.')
            ->call('unassign')
            ->assertHasNoErrors();

        expect($this->group->fresh()->teacher_id)->toBeNull();
    });

    it('con la carga cerrada no sale «Quitar docente», como «Cambiar», y lo que rechaza la Action se ve en el modal sin 500', function () {
        $this->world->load->update(['status' => LoadStatus::Closed]);

        $page = unassignPage($this->jefe, ['estado' => 'todos'])
            ->assertSee('La carga está en Cerrada: ya no se asignan docentes.')
            ->assertDontSeeHtml('data-testid="unassign-teacher"')
            ->assertDontSee('Cambiar')
            ->call('askUnassign', $this->group->id)
            ->set('unassignReason', 'Renunció.')
            ->call('unassign')
            ->assertHasErrors('unassign')
            ->assertSet('showUnassign', true)
            ->assertSee('La carga está en Cerrada: los docentes se asignan en Asignación, Ajustes o Re-análisis.');

        expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
            ->and(lastToastOf($page))->type->toBe('error')->title->toBe('No se quitó el docente');
    });

    it('si el grupo ya no tiene docente, avisa y no abre el modal', function () {
        $this->group->update(['teacher_id' => null]);

        $page = unassignPage($this->jefe, ['estado' => 'todos'])
            ->call('askUnassign', $this->group->id)
            ->assertSet('showUnassign', false);

        expect(lastToastOf($page))->type->toBe('error')->description->toBe('El grupo G ya no tiene docente.');
    });

    it('la Subdirección no ve «Quitar docente» y no puede quitarlo', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        unassignPage($subdireccion, ['depto' => 'SC', 'estado' => 'todos'])
            ->assertSeeText('Beto Salas')
            ->assertDontSeeHtml('data-testid="unassign-teacher"')
            ->call('askUnassign', $this->group->id)
            ->assertForbidden();

        expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
    });

    it('el jefe de otro departamento no ve el grupo ni le quita el docente', function () {
        $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
        $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create();

        unassignPage($jefeCb)
            ->call('askUnassign', $this->group->id)
            ->assertNotFound();

        expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
    });

    it('MS6: el jefe de proyecto docente de otro campus no le quita el docente a un grupo de este', function () {
        $otherCampus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $otherCampus->id, 'department_id' => $this->world->department->id])->create();

        unassignPage($proyecto, ['estado' => 'todos'])
            ->assertSeeText('Beto Salas')
            ->assertDontSeeHtml('data-testid="unassign-teacher"')
            ->call('askUnassign', $this->group->id)
            ->assertForbidden();

        expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
    });

    it('F-02: sin docente, el grupo ya se cancela (CancelGroup, como al armar paralelos)', function () {
        expect(fn () => app(CancelGroup::class)->handle($this->group->fresh(), $this->jefe))
            ->toThrow(ValidationException::class, 'El grupo G tiene docente: quítalo antes de cancelarlo.');

        unassignPage($this->jefe, ['estado' => 'todos'])
            ->call('askUnassign', $this->group->id)
            ->set('unassignReason', 'Sobra: se arma en paralelo.')
            ->call('unassign')
            ->assertHasNoErrors();

        app(CancelGroup::class)->handle($this->group->fresh(), $this->jefe);

        expect($this->group->fresh()->status)->toBe(GroupStatus::Cancelled);
    });
});

describe('F-02: «Cerrar grupo» del re-análisis después de quitar el docente', function () {
    it('la sugerencia deja de estar bloqueada y se aplica', function () {
        $world = ReanalysisWorld::create();
        $jefe = $world->head();
        $world->published()->gradesArrived()->inReanalysis($jefe);
        $e = $world->group($world->integral, $world->isc, 20, $world->c1, 2, day: 2);
        $suggestion = function () use ($world, $e): ?ReanalysisSuggestion {
            $block = app(ReanalysisBoardQuery::class)->block($world->load->fresh(), null, $world->integral->id, $world->c1->id);

            return app(ReanalysisSuggestionsQuery::class)->for($world->load->fresh(), $block)->find("close_group:{$e->id}");
        };

        expect($suggestion()->blockedReason)->toBe("Primero quita a {$e->teacher->name} del grupo {$e->code} en Asignación docente; luego lo puedes cerrar.");

        onCampusPage(null);
        unassignPage($jefe, ['estado' => 'todos'])
            ->call('askUnassign', $e->id)
            ->set('unassignReason', 'Sobra en Campus I: se cierra en el re-análisis.')
            ->call('unassign')
            ->assertHasNoErrors();

        $close = $suggestion();
        expect($close->blockedReason)->toBeNull();

        app(ApplyReanalysisSuggestion::class)->handle($world->load->fresh(), $close, $jefe);

        expect($e->fresh()->status)->toBe(GroupStatus::Cancelled);
    });
});
