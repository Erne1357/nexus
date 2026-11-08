<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Editor de horarios (tarea 3.5; maqueta A «Semana con bandeja», DESIGN.md "Editor de horarios"): el coordinador de la
| carrera dueña y el jefe del departamento de la materia acomodan las sesiones de los grupos de un semestre de una
| retícula; la Subdirección lo ve sin editar, con el motivo; el docente no entra; nadie ve ni toca lo de otra carrera u
| otro campus (reglas-de-negocio.md, "Editor de horarios" y "Aprobaciones y permisos").
*/

const SCHEDULE_EDITOR = 'scheduling::pages.schedule-editor';

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->otherCampus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->isc = editorCareer('ISC', 'Ingeniería en Sistemas Computacionales', $this->sc);
    $this->iind = editorCareer('IIND', 'Ingeniería Industrial', Department::factory()->create());
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2010-224', 'is_current' => true]);
    $this->oldPlan = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2004-296', 'is_current' => false]);

    foreach ([$this->sc, $this->cb] as $department) {
        AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $department->id]);
    }

    $this->calculus = editorSubject('ACF-0904', 'Cálculo Vectorial', $this->cb, theory: 2);
    $this->structures = editorSubject('AED-1026', 'Estructura de Datos', $this->sc, theory: 2, practice: 1);
    $this->calcGroup = editorGroup($this->calculus, 'A');
    $this->dataGroup = editorGroup($this->structures, 'B');

    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
});

function editorCareer(string $code, string $name, Department $department): Career
{
    $career = Career::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->campus->id]);

    return $career;
}

function editorSubject(string $code, string $name, Department $department, int $theory, int $practice = 0): Subject
{
    $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id, 'theory_hours' => $theory, 'practice_hours' => $practice]);
    placeInPlan(test()->plan, $subject, 3);

    return $subject;
}

function editorGroup(Subject $subject, string $code, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'subject_id' => $subject->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => 3,
        'code' => $code,
        ...$attributes,
    ]);
}

/**
 * La página como `$user`, con el campus activo (los puestos sin campus ven todos) y los parámetros de la URL.
 *
 * @param  array<string, string>  $query
 */
function editorAs(User $user, array $query = []): Testable
{
    session([Actor::ACTIVE_CAMPUS => test()->campus->id]);

    return Livewire::withQueryParams(['semestre' => '3', ...$query])->actingAs($user)->test(SCHEDULE_EDITOR);
}

/**
 * Las sesiones de un grupo como «día inicio-fin tipo».
 *
 * @return list<string>
 */
function editorSessionsOf(Group $group): array
{
    return $group->sessions()->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => "{$session->day} ".substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5)." {$session->kind->value}")
        ->all();
}

describe('coordinador de la carrera dueña', function () {
    it('ve la carrera, la retícula vigente y el semestre con sus grupos por materia, y la retícula vieja como prioridad', function () {
        $page = editorAs($this->coordinator)
            ->assertOk()
            ->assertSee('Horarios')
            ->assertSee('Ene-Jun 2027')
            ->assertSee('Campus II')
            ->assertSee('ISIC-2010-224')
            ->assertSee('Vigente')
            ->assertSee('Prioridad')
            ->assertSee('Horas colocadas')
            ->assertSee('0 de 5')
            ->assertSee('0 choques');

        $tray = domOf($page)->querySelector('[data-testid=tray]');

        expect(textOf($tray))->toContain('ACF-0904', 'Cálculo Vectorial', 'ACF-0904 · A', 'Sin docente', '0 de 2 h', 'AED-1026 · B', '0 de 3 h')
            ->and(textOf(domOf($page)->querySelector('[data-testid=editor-semester]')))->toContain('Semestre 3 · 2 sin acomodar');
    });

    it('elige un grupo, ve dónde cabe y coloca una sesión en una celda que cabe', function () {
        $page = editorAs($this->coordinator)
            ->call('pick', $this->calcGroup->id)
            ->assertSet('groupId', $this->calcGroup->id)
            ->assertSee('Quitar selección');

        expect(domOf($page)->querySelector('[data-testid=week] [data-cell="1|07:00"][data-fit=fits]')?->tagName)->toBe('BUTTON');

        $page->call('place', $this->calcGroup->id, 1, '07:00')
            ->call('place', $this->calcGroup->id, 1, '08:00');

        expect(editorSessionsOf($this->calcGroup))->toBe(['1 07:00-09:00 teoria'])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Sesión colocada'])
            ->and(lastToastOf($page)['description'])->toContain('ACF-0904 · A', '2 de 2 h');
    });

    it('coloca teoría hasta cubrir sus horas y luego práctica', function () {
        GroupSession::factory()->for($this->dataGroup)->on(1, '07:00', '09:00')->create();

        editorAs($this->coordinator)->call('place', $this->dataGroup->id, 2, '07:00');

        expect(editorSessionsOf($this->dataGroup))->toBe(['1 07:00-09:00 teoria', '2 07:00-08:00 practica']);
    });

    it('?grupo= abre la carrera, la retícula y el semestre del grupo con el grupo elegido', function () {
        $old = editorGroup(editorSubject('ACF-0901', 'Cálculo Diferencial', $this->cb, theory: 4), 'V', ['study_plan_id' => $this->oldPlan->id, 'semester' => 1]);
        session([Actor::ACTIVE_CAMPUS => $this->campus->id]);

        Livewire::withQueryParams(['grupo' => $old->id])->actingAs($this->coordinator)->test(SCHEDULE_EDITOR)
            ->assertSet('careerId', $this->isc->id)
            ->assertSet('planId', $this->oldPlan->id)
            ->assertSet('semester', '1')
            ->assertSet('groupId', $old->id)
            ->assertSee('ACF-0901 · V')
            ->assertSee('Quitar selección');
    });

    it('avisa de la retícula vieja con grupos sin acomodar y la abre con «Ir»', function () {
        editorGroup(editorSubject('ACF-0901', 'Cálculo Diferencial', $this->cb, theory: 4), 'V', ['study_plan_id' => $this->oldPlan->id, 'semester' => 1]);

        $page = editorAs($this->coordinator);

        expect(textOf(domOf($page)->querySelector('[data-testid=priority-notice]')))->toContain('Retícula ISIC-2004-296: 1 grupo sin acomodar');

        $page->call('choosePlan', $this->oldPlan->id)
            ->assertSet('planId', $this->oldPlan->id)
            ->assertSet('semester', '1')
            ->assertSee('ACF-0901 · V');
    });

    it('HC-01: colocar donde el docente ya da clase muestra el error y no escribe', function () {
        $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $this->calcGroup->update(['teacher_id' => $teacher->id]);
        $busy = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->otherCampus->id, 'teacher_id' => $teacher->id, 'code' => 'F',
            'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
        GroupSession::factory()->for($busy)->on(1, '07:00', '08:00')->create();

        $page = editorAs($this->coordinator)->call('pick', $this->calcGroup->id);
        $cell = domOf($page)->querySelector('[data-testid=week] [data-cell="1|07:00"]');

        expect($cell?->getAttribute('data-fit'))->toBe('blocked')
            ->and($cell?->tagName)->not->toBe('BUTTON')
            ->and($cell?->getAttribute('title'))->toContain('HC-01');

        $page->call('place', $this->calcGroup->id, 1, '07:00');

        expect(editorSessionsOf($this->calcGroup))->toBe([])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'title' => 'No se colocó'])
            ->and(lastToastOf($page)['description'])->toContain('Ana Pérez ya da Química');
    });

    it('mueve una sesión desde su cajón y la quita', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        $page = editorAs($this->coordinator)
            ->call('openSession', $session->id)
            ->assertSet('showSession', true)
            ->assertSet('sessionDay', '1')
            ->assertSet('sessionStart', '07:00')
            ->assertSet('sessionEnd', '09:00')
            ->assertSee('Sesión de ACF-0904 · A')
            ->assertSee('Lo asigna el jefe de Ciencias Básicas')
            ->set('sessionDay', '3')
            ->set('sessionStart', '10:00')
            ->set('sessionEnd', '12:00')
            ->set('sessionKind', 'practica')
            ->call('saveSession')
            ->assertHasNoErrors();

        expect(editorSessionsOf($this->calcGroup))->toBe(['3 10:00-12:00 practica'])
            ->and(lastToastOf($page)['title'])->toBe('Sesión guardada');

        $page->call('removeSession')->assertSet('showSession', false);

        expect(editorSessionsOf($this->calcGroup))->toBe([])
            ->and(lastToastOf($page)['title'])->toBe('Sesión quitada');
    });

    it('marca la sesión con choque duro con su regla y la lista en «Choques del semestre» con «Ver»', function () {
        $room = Room::factory()->create(['code' => 'A-1', 'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
        $mine = GroupSession::factory()->for($this->calcGroup)->inRoom($room)->on(2, '09:00', '10:00')->create();
        $taken = GroupSession::factory()->for($this->dataGroup)->on(2, '09:00', '10:00')->create();
        DB::statement('set constraints group_sessions_room_no_overlap deferred');
        DB::table('group_sessions')->where('id', $taken->id)->update(['room_id' => $room->id]);

        $page = editorAs($this->coordinator)->assertSee('1 choque');
        $block = domOf($page)->querySelector('[data-testid=week] [data-session="'.$mine->id.'"]');

        expect(textOf($block))->toContain('ACF-0904 · A', '9:00–10:00', 'A-1', 'HC-02')
            ->and(textOf(domOf($page)->querySelector('[data-testid=conflicts]')))->toContain('HC-02', 'El salón A-1 ya está ocupado', 'Ver');

        $page->call('focusSession', $mine->id)->assertDispatched('editor-focus', id: $mine->id)->assertSet('day', '2');
    });

    it('cambia a la vista por docente y por salón con los de los grupos del semestre', function () {
        $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $this->calcGroup->update(['teacher_id' => $teacher->id]);
        GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        editorAs($this->coordinator)
            ->call('setView', 'docente')
            ->assertSet('view', 'docente')
            ->assertSee('Ana Pérez')
            ->assertSee('Semana en todos los campus');

        editorAs($this->coordinator, ['vista' => 'docente', 'docente' => Teacher::factory()->create()->id])
            ->assertSet('teacherId', $teacher->id);
    });
});

describe('coordinador de otra carrera', function () {
    beforeEach(function () {
        $this->other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->iind->id])->create();
    });

    it('no ve la carrera ni la puede pedir por la URL', function () {
        $page = editorAs($this->other)->assertOk()->assertDontSee('ACF-0904');

        expect(textOf(domOf($page)->querySelector('[data-testid=editor-career]')))->toContain('IIND')->not->toContain('ISC');

        session([Actor::ACTIVE_CAMPUS => $this->campus->id]);
        Livewire::withQueryParams(['carrera' => $this->isc->id])->actingAs($this->other)->test(SCHEDULE_EDITOR)->assertNotFound();
        Livewire::withQueryParams(['grupo' => $this->calcGroup->id])->actingAs($this->other)->test(SCHEDULE_EDITOR)->assertForbidden();
    });

    it('cada método llamado directo sobre un grupo o sesión de otra carrera da 403 y no escribe', function (string $method, Closure $arguments) {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        editorAs($this->other)->call($method, ...$arguments($this, $session))->assertForbidden();

        expect(editorSessionsOf($this->calcGroup))->toBe(['1 07:00-09:00 teoria']);
    })->with([
        'elegir' => ['pick', fn ($test) => [$test->calcGroup->id]],
        'colocar' => ['place', fn ($test) => [$test->calcGroup->id, 2, '07:00']],
        'abrir la sesión' => ['openSession', fn ($test, $session) => [$session->id]],
        'abrir el grupo' => ['openGroup', fn ($test) => [$test->calcGroup->id]],
        'ver el choque' => ['focusSession', fn ($test, $session) => [$session->id]],
    ]);

    it('guardar o quitar una sesión ajena desde un cajón que abrió el admin da 403', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();
        $page = editorAs(User::factory()->admin()->create())->call('openSession', $session->id);

        $this->actingAs($this->other);
        $page->set('sessionDay', '2')->call('saveSession')->assertForbidden();

        $page = editorAs(User::factory()->admin()->create())->call('openSession', $session->id);
        $this->actingAs($this->other);
        $page->call('removeSession')->assertForbidden();

        expect(editorSessionsOf($this->calcGroup))->toBe(['1 07:00-09:00 teoria']);
    });
});

describe('jefe del departamento de la materia', function () {
    it('coloca las sesiones de los grupos de su departamento, y los de otro departamento los ve de solo lectura', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

        $page = editorAs($jefe)->call('pick', $this->calcGroup->id)->call('place', $this->calcGroup->id, 4, '13:00');

        expect(editorSessionsOf($this->calcGroup))->toBe(['4 13:00-14:00 teoria']);

        $page->call('pick', $this->dataGroup->id)->assertSee('Solo lectura');

        $page->call('place', $this->dataGroup->id, 4, '13:00')->assertForbidden();
    });
});

describe('Subdirección', function () {
    it('ve el semestre y los grupos sin editar: el motivo va una vez en la barra y las celdas no colocan', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $page = editorAs($subdireccion, ['carrera' => $this->isc->id])->assertSee('ACF-0904 · A')->call('pick', $this->calcGroup->id)->assertSee('Solo lectura');
        $dom = domOf($page);

        expect($dom->querySelectorAll('[data-testid=week] button[data-cell]'))->toHaveCount(0)
            ->and($dom->querySelectorAll('[data-testid=week] [data-cell][data-fit=fits]')->length)->toBeGreaterThan(0)
            ->and($dom->querySelectorAll('[data-testid=edit-reason]'))->toHaveCount(1);

        $page->call('place', $this->calcGroup->id, 1, '07:00')->assertForbidden();
    });
});

describe('docente', function () {
    it('no entra a la ruta ni ve la entrada del menú', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($docente)->get(route('scheduling.editor'))->assertForbidden();
    });
});

describe('otro campus', function () {
    it('el coordinador de la carrera en otro campus no ve sus grupos ni los toca', function () {
        $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->isc->id])->create();
        session([Actor::ACTIVE_CAMPUS => $this->otherCampus->id]);

        Livewire::actingAs($elsewhere)->test(SCHEDULE_EDITOR)->assertOk()->assertDontSee('ACF-0904')->assertSee('no ofrece carreras');
        Livewire::actingAs($elsewhere)->test(SCHEDULE_EDITOR)->call('place', $this->calcGroup->id, 1, '07:00')->assertNotFound();
        Livewire::withQueryParams(['carrera' => $this->isc->id])->actingAs($elsewhere)->test(SCHEDULE_EDITOR)->assertNotFound();
    });
});

describe('contexto', function () {
    it('sin campus activo pide elegirlo', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        Livewire::actingAs($subdireccion)->test(SCHEDULE_EDITOR)->assertOk()->assertSee('Elige un campus en tu credencial');
    });

    it('el menú lleva a «Horarios» en Académico a quien ve los horarios', function () {
        $this->actingAs($this->coordinator)->get(route('scheduling.editor'))->assertOk()->assertSee('Horarios');

        expect(textOf(domOf($this->get('/'))->getElementById('nexus-menu')))->toContain('Horarios');
    });
});

it('no dispara consultas de más al pintar ni al colocar una sesión, y no crecen con los grupos del semestre', function () {
    session([Actor::ACTIVE_CAMPUS => $this->campus->id]);
    $this->actingAs($this->coordinator);
    $queries = function (Closure $action): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $page = null;
    $render = function () use ($queries, &$page): int {
        return $queries(function () use (&$page) {
            $page = Livewire::withQueryParams(['semestre' => '3'])->test(SCHEDULE_EDITOR);
        });
    };

    $render();
    $few = $render();

    foreach (range(1, 6) as $index) {
        $group = editorGroup(editorSubject("ZZZ-000{$index}", "Materia {$index}", $this->sc, theory: 3), "G{$index}");
        GroupSession::factory()->for($group)->on(($index % 5) + 1, '07:00', '09:00')->create();
    }

    $many = $render();
    $pick = $queries(fn () => $page->call('pick', $this->calcGroup->id));
    $place = $queries(fn () => $page->call('place', $this->calcGroup->id, 3, '07:00'));

    expect($many)->toBe($few)
        ->and($many)->toBeLessThanOrEqual(26)
        ->and($pick)->toBeLessThanOrEqual(30)
        ->and($place)->toBeLessThanOrEqual(66);
});
