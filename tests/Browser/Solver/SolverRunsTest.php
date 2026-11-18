<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;

/*
| Corridas del motor en el navegador (tarea 6.10, variante C): el jefe entra por el menú, lanza la carga de su
| departamento con el motor de prueba (la cola es síncrona: vuelve lista), revisa la semana con los cambios y la acepta
| desde el modal con el resumen; el horario queda escrito. A 1280 y a 390 px, sin desplazar la página de lado ni errores.
| El motor de prueba se elige en el drawer: el predeterminado es la heurística, y con este escenario (la docente no tiene
| horas que le queden) reporta HC-08 en los tres grupos, así que al aceptar se quedarían como están (ApplyPlan).
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $sc->id]);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $sc->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'ISIC-2010']);
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'capacity' => 40, 'code' => 'LC-1']);
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);

    $this->groups = collect(['SCD-1015' => 'Lenguajes y Autómatas I', 'SCD-1016' => 'Lenguajes de Interfaz', 'SCC-1014' => 'Redes de Computadoras'])
        ->map(fn (string $name, string $code): Group => Group::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id,
            'subject_id' => Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $sc->id, 'theory_hours' => 2, 'practice_hours' => 0])->id,
            'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'semester' => 6, 'teacher_id' => $teacher->id,
        ]));
    GroupSession::factory()->for($this->groups['SCD-1015'])->inRoom($room)->on(3, '12:00', '13:00')->create();

    User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $sc->id])->create(['username' => '19110005']);
});

/**
 * Entra como el jefe y abre «Corridas del motor» desde el menú.
 */
function openRunsAsHead(int $width)
{
    return visit('/login')
        ->resize($width, 900)
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/solver/runs')
        ->waitForText('Corridas del motor')
        ->assertSee('Todavía no hay corridas de esta carga.');
}

it('a 1280 px el jefe lanza con el motor de prueba, revisa la semana y acepta la corrida', function () {
    $page = openRunsAsHead(1280)
        ->click('@launch-open')
        ->assertSeeIn('@launch-drawer', 'Lanzar una corrida')
        ->assertSeeIn('@launch-weights', 'SC-01')
        ->click('Motor de prueba')
        ->click('@launch-submit')
        ->waitForText('Corrida lanzada')
        ->assertVisible('@run-week')
        ->assertSeeIn('@run-tray', 'SCD-1016')
        ->assertSee('Cambian')
        ->click('Como está hoy')
        ->wait(0.5)
        ->click('Con la corrida')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('@run-accept')
        ->assertSeeIn('@accept-modal', '¿Aceptar la corrida 1?')
        ->click('@accept-confirm')
        ->waitForText('Corrida aplicada')
        ->assertSee('Aplicada')
        ->assertNoJavaScriptErrors();

    expect(SolverRun::query()->sole())->status->toBe(SolverRunStatus::Applied)->engine->toBe('fixed')
        ->and($this->groups['SCD-1016']->sessions()->count())->toBeGreaterThan(0);
});

it('a 390 px la semana es la lista de un día y el jefe acepta sin desplazar la página de lado', function () {
    openRunsAsHead(390)
        ->click('@launch-open')
        ->click('Motor de prueba')
        ->click('@launch-submit')
        ->waitForText('Corrida lanzada')
        ->assertVisible('@run-day-list')
        ->assertMissing('@run-week')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('@run-accept')
        ->click('@accept-confirm')
        ->waitForText('Corrida aplicada')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    expect(SolverRun::query()->sole())->status->toBe(SolverRunStatus::Applied)->engine->toBe('fixed');
});
