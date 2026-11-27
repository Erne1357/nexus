<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Staffing\Queries\CurrentLoadQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Carga actual» (tarea 1.57, SEL-18): la lista de los grupos del departamento agrupada por materia; el jefe cambia ahí
| el tipo de espacio de la materia (SEL-17) y el cupo de cada grupo; el JPD y los demás la ven de solo lectura.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->redes = $this->world->subject('SCD-1021', 'Redes de Computadoras');
    $this->room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->world->campus->id]))->create(['code' => '703']);

    $this->d = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00'], [2, '10:00', '11:00'], [3, '10:00', '11:00']], ['capacity' => 25]);
    $this->g = $this->world->group($this->poo, $this->isc, 'G', 2, [[2, '13:00', '15:00']], ['capacity' => 30]);
    $this->h = $this->world->group($this->redes, $this->isc, 'H', 5, [[5, '16:00', '18:00']], ['capacity' => 20]);
    $this->d->sessions()->update(['room_id' => $this->room->id]);
    $this->teacher = $this->world->teacher('Ana Ruiz');
    $this->g->update(['teacher_id' => $this->teacher->id]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    $this->proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create();
});

/**
 * @param  array<string, string>  $query
 */
function currentLoadPage(User $user, array $query = []): Testable
{
    onCampusPage(test()->world->campus);

    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.current-load');
}

describe('lista', function () {
    it('SEL-18: muestra los grupos agrupados por materia con sus fichas de sesión y salón, docente y avance', function () {
        currentLoadPage($this->jefe)
            ->assertOk()
            ->assertSeeInOrder(['Carga actual', 'Sistemas y Computación', 'Presencial', 'Ene-Jun 2027'])
            ->assertSeeText('1 de 3 con docente')
            ->assertSeeInOrder(['Programación Orientada a Objetos', 'SCC-1013', 'Redes de Computadoras', 'SCD-1021'])
            ->assertSeeText(['Lu–Mi 10–11 · 703', 'Ma 13–15', 'Ana Ruiz', 'Vi 16–18'])
            ->assertSeeText('Sin salón')
            ->assertSeeText('Sin docente')
            ->assertSeeHtml('data-subject="SCC-1013"');
    });

    it('SEL-18: cada grupo abre su cajón en lugar de los enlaces provisionales a Horarios y Asignación', function () {
        currentLoadPage($this->jefe)
            ->assertSeeHtml('data-testid="open-group"')
            ->assertSeeHtml('current-load-drawer')
            ->assertDontSeeHtml(e(route('scheduling.editor', ['modalidad' => 'PRES', 'campus' => 'C2', 'grupo' => $this->d->id])))
            ->assertDontSeeHtml(e(route('staffing.assign', ['modalidad' => 'PRES', 'depto' => 'SC', 'grupo' => $this->d->id])));
    });

    it('los filtros rápidos cuentan y filtran: sin docente, sin salón, con choque y espacio sin marcar', function () {
        ScheduleConflict::factory()->forGroup($this->d)->create(['rule' => 'HC-01', 'message' => 'Docente ocupado.']);
        $this->poo->update(['required_space' => RequiredSpace::Aula]);

        currentLoadPage($this->jefe)
            ->assertSeeHtml('data-testid="chip-sin_docente"')
            ->set('state', 'sin_docente')->assertSeeHtml('data-group="'.$this->d->id.'"')->assertDontSeeHtml('data-group="'.$this->g->id.'"')
            ->set('state', 'sin_salon')->assertSeeHtml('data-group="'.$this->g->id.'"')->assertDontSeeHtml('data-group="'.$this->d->id.'"')
            ->set('state', 'choque')->assertSeeHtml('data-group="'.$this->d->id.'"')->assertDontSeeHtml('data-group="'.$this->g->id.'"')->assertSeeText('HC-01')
            ->set('state', 'sin_espacio')->assertSeeHtml('data-group="'.$this->h->id.'"')->assertDontSeeHtml('data-group="'.$this->d->id.'"');
    });

    it('busca por grupo, materia o docente sin importar acentos', function () {
        currentLoadPage($this->jefe)
            ->set('search', 'programacion')->assertSeeHtml('data-subject="SCC-1013"')->assertDontSeeHtml('data-subject="SCD-1021"')
            ->set('search', 'ana ruiz')->assertSeeHtml('data-group="'.$this->g->id.'"')->assertDontSeeHtml('data-group="'.$this->d->id.'"');
    });

    it('con un volumen de grupos las consultas no crecen con ellos', function () {
        $count = fn () => (function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(CurrentLoadQuery::class)->board($this->world->term->id, modalityOf('PRES')->id, $this->world->department->id);

            return count(DB::getQueryLog());
        })->call($this);

        $small = $count();

        foreach (range(1, 40) as $n) {
            $subject = $this->world->subject("VOL-{$n}", "Materia {$n}");
            $group = $this->world->group($subject, $this->isc, 'A', 3, [[1, '07:00', '08:00'], [3, '07:00', '08:00']]);
            ScheduleConflict::factory()->forGroup($group)->create();
        }

        expect($count())->toBe($small);
    });
});

describe('edición', function () {
    it('SEL-17: el jefe cambia el tipo de espacio de la materia y aplica a todos sus grupos', function () {
        $page = currentLoadPage($this->jefe)->call('setSpace', $this->poo->id, 'sala_computo');

        expect($this->poo->fresh()->required_space)->toBe(RequiredSpace::SalaComputo)
            ->and(lastToastOf($page))->type->toBe('success');
        $page->assertSeeHtml('data-testid="space-SCC-1013"');
    });

    it('SEL-17: el JPD ve el control de solo lectura y llamar setSpace es 403', function () {
        currentLoadPage($this->proyecto)
            ->assertOk()
            ->assertSeeHtml('disabled')
            ->call('setSpace', $this->poo->id, 'laboratorio')
            ->assertForbidden();

        expect($this->poo->fresh()->required_space)->toBeNull();
    });

    it('SEL-18: el jefe edita el cupo de un grupo y queda guardado', function () {
        $page = currentLoadPage($this->jefe)
            ->set("capacities.{$this->d->id}", '28')
            ->call('saveCapacity', $this->d->id)
            ->assertHasNoErrors();

        expect($this->d->fresh()->capacity)->toBe(28)
            ->and(lastToastOf($page))->type->toBe('success');
    });

    it('un cupo inválido o que SetGroupCapacity rechaza se ve junto al campo y no cambia nada', function () {
        $page = currentLoadPage($this->jefe)
            ->set("capacities.{$this->d->id}", 'abc')->call('saveCapacity', $this->d->id)
            ->assertHasErrors(["capacities.{$this->d->id}"])
            ->set("capacities.{$this->d->id}", '5000')->call('saveCapacity', $this->d->id)
            ->assertHasErrors(["capacities.{$this->d->id}"]);

        expect($this->d->fresh()->capacity)->toBe(25);
    });

    it('HC-05: un cupo que no cabe en el salón se rechaza con su mensaje', function () {
        $this->room->update(['capacity' => 26]);

        currentLoadPage($this->jefe)
            ->set("capacities.{$this->d->id}", '40')->call('saveCapacity', $this->d->id)
            ->assertHasErrors(["capacities.{$this->d->id}"])
            ->assertSeeText('HC-05');

        expect($this->d->fresh()->capacity)->toBe(25);
    });

    it('el JPD no edita cupos: llamar saveCapacity es 403', function () {
        currentLoadPage($this->proyecto)
            ->set("capacities.{$this->d->id}", '28')
            ->call('saveCapacity', $this->d->id)
            ->assertForbidden();

        expect($this->d->fresh()->capacity)->toBe(25);
    });
});

describe('alcance', function () {
    beforeEach(function () {
        $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    });

    it('el jefe de otro departamento no ve ni edita la carga de este', function () {
        $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

        currentLoadPage($jefeCb, ['depto' => 'SC'])->assertForbidden();

        currentLoadPage($jefeCb)
            ->assertSee('Ciencias Básicas')
            ->assertDontSeeHtml('data-subject="SCC-1013"')
            ->call('saveCapacity', $this->d->id)->assertNotFound();

        currentLoadPage($jefeCb)->call('setSpace', $this->poo->id, 'aula')->assertNotFound();

        expect($this->d->fresh()->capacity)->toBe(25)
            ->and($this->poo->fresh()->required_space)->toBeNull();
    });

    it('el coordinador y el docente no abren la pantalla', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->world->campus->id, 'career_id' => $this->isc->id])->create();
        $docente = User::factory()->withRole(Role::Docente)->create();

        currentLoadPage($coordinador)->assertForbidden();
        currentLoadPage($docente)->assertForbidden();
    });

    it('Subdirección y directivo la ven de solo lectura y el admin edita', function () {
        $page = fn (User $user) => currentLoadPage($user, ['depto' => 'SC']);

        // Subdirección, directivo y admin la ven; solo el admin edita (SEL-17: el jefe del departamento y el admin).
        foreach ([Role::Subdireccion, Role::Directivo] as $role) {
            $user = User::factory()->withRole($role)->create();
            $page($user)->assertOk()->assertSeeHtml('data-subject="SCC-1013"')->assertSeeHtml('disabled');
            $page($user)->call('saveCapacity', $this->d->id)->assertForbidden();
            $page($user)->call('setSpace', $this->poo->id, 'aula')->assertForbidden();
        }

        $page(User::factory()->admin()->create())->assertOk()->call('setSpace', $this->poo->id, 'aula');
        expect($this->poo->fresh()->required_space)->toBe(RequiredSpace::Aula);
    });
});

describe('menú y visibilidad', function () {
    $labels = function (User $user): array {
        test()->actingAs($user);

        return array_map(fn ($item) => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)->forUser($user)));
    };

    it('«Carga actual» sale al jefe, al JPD, a Subdirección y al admin; no al coordinador ni al docente', function () use ($labels) {
        expect($labels($this->jefe))->toContain('Carga actual')
            ->and($labels($this->proyecto))->toContain('Carga actual')
            ->and($labels(User::factory()->withRole(Role::Subdireccion)->create()))->toContain('Carga actual')
            ->and($labels(User::factory()->admin()->create()))->toContain('Carga actual')
            ->and($labels(User::factory()->withRole(Role::Docente)->create()))->not->toContain('Carga actual');
    });

    it('MN-06: con la carga oculta, el jefe la sigue abriendo (es de todas las etapas) salvo que el admin apague las de su etapa; el admin siempre', function () {
        app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), false);

        $this->actingAs($this->jefe)->get(route('staffing.current-load'))->assertOk();

        app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::HEADS_SETTING), false);
        app(AcademicLoadVisibility::class)->forget();

        $this->actingAs($this->jefe)->get(route('staffing.current-load'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('staffing.current-load'))->assertOk();
    });
});
