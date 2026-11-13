<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Dom\Element;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Editor de horarios (tarea 3.5; maqueta A «Semana con bandeja», DESIGN.md "Editor de horarios"): el coordinador de la
| carrera dueña y el jefe del departamento de la materia acomodan las sesiones de los grupos de un semestre de una
| retícula; la Subdirección lo ve sin editar, con el motivo; el docente no entra; nadie ve ni toca lo de otra carrera u
| otro campus (reglas-de-negocio.md, "Editor de horarios" y "Aprobaciones y permisos").
*/

const SCHEDULE_EDITOR = 'scheduling::pages.schedule-editor';

const SESSION_DRAWER = 'scheduling::components.session-drawer';

const GROUP_DRAWER = 'scheduling::components.group-drawer';

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
        AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $department->id]);
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
    onCampusPage(test()->campus);

    return Livewire::withQueryParams(['semestre' => '3', ...$query])->actingAs($user)->test(SCHEDULE_EDITOR);
}

/**
 * Un cajón del editor como `$user`, con el periodo y el campus de la página.
 */
function editorDrawerAs(User $user, string $drawer): Testable
{
    return Livewire::actingAs($user)->test($drawer, ['termId' => test()->term->id, 'campusId' => test()->campus->id]);
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
            ->assertSee('0 choques');

        $tray = domOf($page)->querySelector('[data-testid=tray]');

        expect(textOf(domOf($page)->querySelector('[data-testid=editor-facts]')))->toContain('Horas colocadas 0 de 5')
            ->and(textOf($tray))->toContain('ACF-0904', 'Cálculo Vectorial', 'ACF-0904 · A', 'Sin docente', '0 de 2 h', 'AED-1026 · B', '0 de 3 h')
            ->and(textOf(domOf($page)->querySelector('[data-testid=editor-semester]')))->toContain('Semestre 3 · 2 sin acomodar');
    });

    it('la bandeja es una sola parada de tabulación: la pieza elegida o la primera, con los botones del cajón fuera del Tab', function () {
        $stops = fn (Testable $page): array => array_map(fn (Element $element): string => $element->getAttribute('data-group') ?? 'cajón', [...domOf($page)->querySelectorAll('[data-testid=tray] [tabindex="0"]')]);

        expect($stops(editorAs($this->coordinator)))->toBe([$this->calcGroup->id])
            ->and($stops(editorAs($this->coordinator)->call('pick', $this->dataGroup->id)))->toBe([$this->dataGroup->id])
            ->and(domOf(editorAs($this->coordinator))->querySelectorAll('[data-testid=tray] [data-group-drawer][tabindex="-1"]')->length)->toBe(2)
            ->and(domOf(editorAs($this->coordinator))->querySelector('[data-testid=skip-to-week]')?->getAttribute('href'))->toBe('#editor-week');
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

    it('avisa «Colocada con choque» con cualquier regla dura nueva del grupo, aunque no nombre la sesión (HC-09), y no con las que ya tenía (HC-08)', function () {
        $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $this->calcGroup->update(['teacher_id' => $teacher->id]);
        TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);

        $page = editorAs($this->coordinator)->call('place', $this->calcGroup->id, 1, '07:00');

        expect(editorSessionsOf($this->calcGroup))->toBe(['1 07:00-08:00 teoria'])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'warning', 'title' => 'Colocada con choque'])
            ->and(lastToastOf($page)['description'])->toContain('HC-09', 'Ana Pérez no está disponible');

        $page->call('place', $this->calcGroup->id, 3, '07:00');

        expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Sesión colocada']);
    });

    it('con un grupo elegido, las sesiones de los demás grupos quedan bajo las celdas y fuera de las flechas', function () {
        $mine = GroupSession::factory()->for($this->calcGroup)->on(2, '07:00', '08:00')->create();
        $other = GroupSession::factory()->for($this->dataGroup)->on(1, '13:00', '14:00')->create();

        $dom = domOf(editorAs($this->coordinator)->call('pick', $this->calcGroup->id));
        $under = $dom->querySelector('[data-testid=week] [data-session="'.$other->id.'"]');

        expect($under?->hasAttribute('data-nav'))->toBeFalse()
            ->and($under?->getAttribute('tabindex'))->toBe('-1')
            ->and($dom->querySelector('[data-testid=week] [data-session="'.$mine->id.'"]')?->hasAttribute('data-nav'))->toBeTrue();
    });

    it('si después de guardar cupos o salón el grupo elegido ya no es del semestre, suelta la selección sin 403', function () {
        $page = editorAs($this->coordinator)->call('pick', $this->calcGroup->id)->assertSet('groupId', $this->calcGroup->id);
        $this->calcGroup->update(['semester' => 4]);
        app()->forgetScopedInstances(); // otra petición: la Query del editor memoriza por petición

        $page->dispatch('quotas-saved')->assertOk()->assertSet('groupId', '')->assertDontSee('Quitar selección');

        $page->call('pick', $this->dataGroup->id)->dispatch('session-room-saved')->assertOk()->assertSet('groupId', $this->dataGroup->id);
    });

    it('coloca teoría hasta cubrir sus horas y luego práctica', function () {
        GroupSession::factory()->for($this->dataGroup)->on(1, '07:00', '09:00')->create();

        editorAs($this->coordinator)->call('place', $this->dataGroup->id, 2, '07:00');

        expect(editorSessionsOf($this->dataGroup))->toBe(['1 07:00-09:00 teoria', '2 07:00-08:00 practica']);
    });

    it('?grupo= abre la carrera, la retícula y el semestre del grupo con el grupo elegido', function () {
        $old = editorGroup(editorSubject('ACF-0901', 'Cálculo Diferencial', $this->cb, theory: 4), 'V', ['study_plan_id' => $this->oldPlan->id, 'semester' => 1]);
        onCampusPage($this->campus);

        Livewire::withQueryParams(['grupo' => $old->id])->actingAs($this->coordinator)->test(SCHEDULE_EDITOR)
            ->assertSet('careerId', $this->isc->id)
            ->assertSet('planId', $this->oldPlan->id)
            ->assertSet('semester', '1')
            ->assertSet('groupId', $old->id)
            ->assertSee('ACF-0901 · V')
            ->assertSee('Quitar selección');
    });

    it('?grupo= de un grupo de otra carrera que ve por el cupo de la suya abre su carrera, en la retícula y el semestre de la materia', function () {
        $industrial = StudyPlan::factory()->create(['career_id' => $this->iind->id]);
        $shared = editorGroup($this->calculus, 'S', ['owner_career_id' => $this->iind->id, 'study_plan_id' => $industrial->id, 'semester' => 5]);
        GroupQuota::factory()->create(['group_id' => $shared->id, 'career_id' => $this->iind->id, 'is_owner' => true]);
        GroupQuota::factory()->create(['group_id' => $shared->id, 'career_id' => $this->isc->id, 'is_owner' => false]);
        onCampusPage($this->campus);

        Livewire::withQueryParams(['grupo' => $shared->id])->actingAs($this->coordinator)->test(SCHEDULE_EDITOR)
            ->assertOk()
            ->assertSet('careerId', $this->isc->id)
            ->assertSet('planId', $this->plan->id)
            ->assertSet('semester', '3')
            ->assertSet('groupId', $shared->id)
            ->assertSee('Grupo de IIND');
    });

    it('«Ver» y elegir solo toman grupos del semestre: uno de otro semestre no entra a la URL', function () {
        $elsewhere = editorGroup(editorSubject('ACF-0901', 'Cálculo Diferencial', $this->cb, theory: 4), 'V', ['semester' => 1]);

        editorAs($this->coordinator)->call('pick', $this->calcGroup->id)
            ->call('pick', $elsewhere->id, false)->assertForbidden();

        editorAs($this->coordinator)->call('pick', $this->calcGroup->id)->assertSet('groupId', $this->calcGroup->id);
    });

    it('en celular, con un grupo elegido, la hora que cubre la sesión de otro grupo también coloca (marcada si choca)', function () {
        GroupSession::factory()->for($this->dataGroup)->on(1, '07:00', '09:00')->create();

        $list = domOf(editorAs($this->coordinator)->call('pick', $this->calcGroup->id))->querySelector('[data-testid=day-list] [data-day="1"]');
        $eight = $list?->querySelector('button[aria-label^="Colocar ACF-0904 · A el lunes a las 8:00"]');

        expect($eight)->not->toBeNull()
            ->and(textOf($list))->toContain('AED-1026 · B');
    });

    it('una retícula inactiva con grupos en el periodo sale en el segmentado como «Inactiva» y se abre', function () {
        $retired = StudyPlan::factory()->inactive()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-1993-001', 'is_current' => false]);
        editorGroup(editorSubject('ACF-0902', 'Cálculo Integral', $this->cb, theory: 4), 'R', ['study_plan_id' => $retired->id, 'semester' => 2]);

        $page = editorAs($this->coordinator);

        expect(textOf(domOf($page)->querySelector('[data-testid="plan-ISIC-1993-001"]')))->toContain('Inactiva', 'Prioridad');

        $page->call('choosePlan', $retired->id)->assertOk()->assertSet('semester', '2')->assertSee('ACF-0902 · R');
    });

    it('con una sola retícula la muestra como texto y, si es vieja, con «Prioridad» y sus grupos sin acomodar', function () {
        view()->share('errors', new ViewErrorBag);
        $html = view('scheduling::editor.controls', [
            'careers' => [['id' => $this->isc->id, 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']],
            'career' => $this->isc, 'plan' => $this->oldPlan, 'view' => 'semestre', 'teacherOptions' => [], 'roomOptions' => [],
            'plans' => [['id' => $this->oldPlan->id, 'code' => 'ISIC-2004-296', 'is_current' => false, 'is_active' => true, 'unscheduled' => 2]],
            'semesters' => [['semester' => 3, 'unscheduled' => 2]],
            'modalityOptions' => collect([modalityOf('PRES')]), 'shownModality' => modalityOf('PRES'),
            'campusOptions' => collect([$this->campus]), 'campusCode' => $this->campus->code, 'campusCounts' => null,
        ])->render();
        $plan = domOf($html)->querySelector('[data-testid="plan-ISIC-2004-296"]');

        expect($plan?->tagName)->toBe('DIV')
            ->and(textOf($plan))->toContain('ISIC-2004-296', 'Prioridad', '2 grupos sin acomodar');
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

    it('HC-01: la celda donde el docente ya da clase es un botón deshabilitado con su motivo que deja tocar las sesiones de abajo, y colocar ahí muestra el error y no escribe', function () {
        $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $this->calcGroup->update(['teacher_id' => $teacher->id]);
        $busy = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->otherCampus->id, 'teacher_id' => $teacher->id, 'code' => 'F',
            'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
        GroupSession::factory()->for($busy)->on(1, '07:00', '08:00')->create();

        $page = editorAs($this->coordinator)->call('pick', $this->calcGroup->id);
        $cell = domOf($page)->querySelector('[data-testid=week] [data-cell="1|07:00"]');

        expect($cell?->getAttribute('data-fit'))->toBe('blocked')
            ->and($cell?->getAttribute('aria-disabled'))->toBe('true')
            ->and($cell?->hasAttribute('wire:click'))->toBeFalse()
            ->and($cell?->getAttribute('data-why'))->toStartWith('No se puede: HC-01')
            ->and(explode(' ', (string) $cell?->getAttribute('class')))->toContain('pointer-events-none')
            ->and(explode(' ', (string) $cell?->querySelector('[data-why-target]')?->getAttribute('class')))->toContain('pointer-events-auto')
            ->and(textOf(domOf($page)->getElementById((string) $cell?->getAttribute('aria-describedby'))))->toContain('HC-01', 'Ana Pérez ya da Química');

        $page->call('place', $this->calcGroup->id, 1, '07:00');

        expect(editorSessionsOf($this->calcGroup))->toBe([])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'title' => 'No se colocó'])
            ->and(lastToastOf($page)['description'])->toContain('Ana Pérez ya da Química');
    });

    it('mueve una sesión desde su cajón, que avisa a la página, y la quita', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        $drawer = editorDrawerAs($this->coordinator, SESSION_DRAWER)
            ->dispatch('open-session', id: $session->id)
            ->assertSet('show', true)
            ->assertSet('day', '1')
            ->assertSet('startsAt', '07:00')
            ->assertSet('endsAt', '09:00')
            ->assertSee('Sesión de ACF-0904 · A')
            ->assertSee('Lo asigna el jefe de Ciencias Básicas')
            ->set('day', '3')
            ->set('startsAt', '10:00')
            ->set('endsAt', '12:00')
            ->set('kind', 'practica')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('schedule-changed');

        expect(editorSessionsOf($this->calcGroup))->toBe(['3 10:00-12:00 practica'])
            ->and(lastToastOf($drawer)['title'])->toBe('Sesión guardada');

        $drawer->call('remove')->assertSet('show', false)->assertDispatched('schedule-changed');

        expect(editorSessionsOf($this->calcGroup))->toBe([])
            ->and(lastToastOf($drawer)['title'])->toBe('Sesión quitada');
    });

    it('el horario sin guardar se nota en el navegador: el salón cede su lugar a su motivo y solo «Guardar horario» guarda, si hay cambios', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
        Room::factory()->capacity(40)->create(['code' => 'A-7', 'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
        $session = GroupSession::factory()->for($this->calcGroup)->on(2, '09:00', '10:00')->create();

        $drawer = editorDrawerAs($jefe, SESSION_DRAWER)->dispatch('open-session', id: $session->id)->assertSee('Salones sugeridos');
        $dom = domOf($drawer);
        $gate = $dom->querySelector('[data-testid=room-gate]');
        $save = $dom->querySelector('[data-testid=save-session]');

        // Cambiar el día, las horas o el tipo no va al servidor: el navegador compara con lo guardado (`panel`).
        expect(str_replace('\\u0022', '"', (string) $dom->querySelector('[data-testid=session-form]')?->getAttribute('x-data')))->toContain('"day":"day"', '"startsAt":"starts_at"', '"endsAt":"ends_at"', '"kind":"kind"')
            ->and($dom->querySelector('select[wire\\:model="startsAt"]'))->not->toBeNull()
            ->and($dom->querySelector('select[wire\\:model\\.live="startsAt"]'))->toBeNull()
            ->and($dom->querySelector('[data-testid=room-section]')?->getAttribute('x-show'))->toBe('! dirty')
            ->and($gate?->getAttribute('x-show'))->toBe('dirty')
            ->and(textOf($gate))->toContain('Guarda el horario para ver los salones libres a esa hora', 'Descartar cambios')
            ->and(textOf($save))->toBe('Guardar horario')
            ->and($save?->getAttribute('x-bind:disabled'))->toBe('! dirty');

        $drawer->call('save')->assertNotDispatched('schedule-changed');
        expect(editorSessionsOf($this->calcGroup))->toBe(['2 09:00-10:00 teoria']);

        $drawer->set('day', '4')->set('kind', 'practica')->call('save')->assertHasNoErrors()->assertDispatched('schedule-changed')
            ->assertSet('panel.day', 4)->assertSet('day', '4')->assertSet('kind', 'practica');
        expect(editorSessionsOf($this->calcGroup))->toBe(['4 09:00-10:00 practica']);
    });

    it('quien no acomoda el grupo ve el cajón de la sesión con «Guardar horario» deshabilitado y sin el aviso de cambios', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(2, '09:00', '10:00')->create();
        $dom = domOf(editorDrawerAs(User::factory()->withRole(Role::Subdireccion)->create(), SESSION_DRAWER)->dispatch('open-session', id: $session->id));
        $save = $dom->querySelector('[data-testid=save-session]');

        expect($dom->querySelector('[data-testid=session-reason]'))->not->toBeNull()
            ->and($save?->hasAttribute('disabled'))->toBeTrue()
            ->and($save?->hasAttribute('x-bind:disabled'))->toBeFalse()
            ->and($dom->querySelector('[data-testid=room-gate]'))->toBeNull()
            ->and($dom->querySelector('[data-testid=session-room]'))->not->toBeNull();
    });

    it('cambiar el tipo en el cajón no vuelve a validar el grupo: valida al abrir y al guardar', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();
        $drawer = editorDrawerAs($this->coordinator, SESSION_DRAWER)->call('open', $session->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $drawer->set('kind', 'practica')->assertSee('Sesión de ACF-0904 · A');
        DB::disableQueryLog();

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(1);
    });

    it('el cajón del grupo muestra su materia, docente, sesiones y cupos', function () {
        GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        editorDrawerAs($this->coordinator, GROUP_DRAWER)
            ->dispatch('open-group', id: $this->calcGroup->id)
            ->assertSet('show', true)
            ->assertSee('Cálculo Vectorial')
            ->assertSee('CB · Ciencias Básicas')
            ->assertSee('Lo asigna Asignación docente')
            ->assertSeeText('2 de 2 h')
            ->assertSee('Cupos por carrera');
    });

    it('marca la sesión con choque duro con su regla y la lista en «Choques del semestre» con «Ver»', function () {
        $room = Room::factory()->create(['code' => 'A-1', 'capacity' => 40, 'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
        $mine = GroupSession::factory()->for($this->calcGroup)->inRoom($room)->on(2, '09:00', '10:00')->create();
        $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
        $taken = GroupSession::factory()->for($other)->on(2, '09:00', '10:00')->create();
        DB::statement('set constraints group_sessions_room_no_overlap deferred');
        DB::table('group_sessions')->where('id', $taken->id)->update(['room_id' => $room->id]);

        $page = editorAs($this->coordinator);
        $block = domOf($page)->querySelector('[data-testid=week] [data-session="'.$mine->id.'"]');

        expect(textOf(domOf($page)->querySelector('[data-testid=editor-facts]')))->toContain('1 choque', '1 aviso')
            ->and(textOf($block))->toContain('ACF-0904 · A', '9:00–10:00', 'A-1', 'HC-02')
            ->and(textOf(domOf($page)->querySelector('[data-testid=conflicts]')))->toContain('HC-02', 'El salón A-1 ya está ocupado', 'Ver');

        expect($page->html())->toContain("found('{$mine->id}')");
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

    it('«Ver por» desde un grupo elegido muestra a su docente y el salón de su primera sesión con salón, y al volver sigue elegido', function () {
        $building = Building::factory()->create(['campus_id' => $this->campus->id]);
        $first = Room::factory()->create(['code' => 'A-1', 'building_id' => $building->id]);
        $own = Room::factory()->create(['code' => 'B-9', 'building_id' => $building->id]);
        $this->calcGroup->update(['teacher_id' => Teacher::factory()->create(['name' => 'Ana Pérez'])->id]);
        $this->dataGroup->update(['teacher_id' => ($zoe = Teacher::factory()->create(['name' => 'Zoé Ruiz']))->id]);
        GroupSession::factory()->for($this->calcGroup)->inRoom($first)->on(1, '07:00', '09:00')->create();
        GroupSession::factory()->for($this->dataGroup)->on(1, '09:00', '10:00')->create();
        GroupSession::factory()->for($this->dataGroup)->inRoom($own)->on(3, '09:00', '11:00')->create();
        $barOf = fn (Testable $page): string => textOf(domOf($page)->querySelector('[data-testid=person-bar]'));

        $page = editorAs($this->coordinator)->call('pick', $this->dataGroup->id)->call('setView', 'docente')->assertSet('teacherId', $zoe->id);
        expect($barOf($page))->toContain('Zoé Ruiz');

        $page->call('setView', 'salon')->assertSet('roomId', $own->id);
        expect($barOf($page))->toContain('B-9');

        $page->call('setView', 'semestre')->assertSet('groupId', $this->dataGroup->id)->assertSeeHtml('data-testid="selection-bar"');
    });

    it('«Ver por» desde un grupo sin docente ni salón muestra al primero de la lista', function () {
        $building = Building::factory()->create(['campus_id' => $this->campus->id]);
        $room = Room::factory()->create(['code' => 'A-1', 'building_id' => $building->id]);
        $this->calcGroup->update(['teacher_id' => ($ana = Teacher::factory()->create(['name' => 'Ana Pérez']))->id]);
        GroupSession::factory()->for($this->calcGroup)->inRoom($room)->on(1, '07:00', '09:00')->create();
        GroupSession::factory()->for($this->dataGroup)->on(1, '09:00', '10:00')->create();

        editorAs($this->coordinator)->call('pick', $this->dataGroup->id)
            ->call('setView', 'docente')->assertSet('teacherId', $ana->id)
            ->call('setView', 'salon')->assertSet('roomId', $room->id);
    });

    it('en la vista por docente, una sesión de otro campus fuera del horario de este se ve con su altura y cuenta sus horas', function () {
        $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $this->calcGroup->update(['teacher_id' => $teacher->id]);
        $elsewhere = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->otherCampus->id, 'teacher_id' => $teacher->id, 'code' => 'S1']);
        $early = GroupSession::factory()->for($elsewhere)->on(6, '06:00', '08:00')->create();

        $dom = domOf(editorAs($this->coordinator, ['vista' => 'docente']));
        $block = $dom->querySelector('[data-testid=week] [data-session="'.$early->id.'"]');

        expect($block?->getAttribute('style'))->toContain('--top: 0;', '--span: 2;')
            ->and(textOf($dom->querySelector('[data-testid=week]')))->toContain('Sábado 2 h ocupadas', '6:00')
            ->and(textOf($block))->toContain('Campus I');
    });
});

describe('coordinador de otra carrera', function () {
    beforeEach(function () {
        $this->other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->iind->id])->create();
    });

    it('«Ver por» con un grupo que no es de su semestre no toma su docente ni su salón', function () {
        $ana = Teacher::factory()->create(['name' => 'Ana Pérez']);
        $room = Room::factory()->create(['code' => 'A-1', 'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
        $this->calcGroup->update(['teacher_id' => $ana->id]);
        GroupSession::factory()->for($this->calcGroup)->inRoom($room)->on(1, '07:00', '09:00')->create();

        $page = editorAs($this->other, ['vista' => 'docente'])->set('groupId', $this->calcGroup->id)
            ->call('setView', 'docente')->call('setView', 'salon')->assertOk();

        expect($page->get('teacherId'))->not->toBe($ana->id)
            ->and($page->get('roomId'))->not->toBe($room->id)
            ->and($page->html())->not->toContain('Ana Pérez');
    });

    it('no ve la carrera ni la puede pedir por la URL', function () {
        $page = editorAs($this->other)->assertOk()->assertDontSee('ACF-0904');

        expect(textOf(domOf($page)->querySelector('[data-testid=editor-career]')))->toContain('IIND')->not->toContain('ISC');

        onCampusPage($this->campus);
        Livewire::withQueryParams(['carrera' => $this->isc->id])->actingAs($this->other)->test(SCHEDULE_EDITOR)->assertNotFound();
        Livewire::withQueryParams(['grupo' => $this->calcGroup->id])->actingAs($this->other)->test(SCHEDULE_EDITOR)->assertForbidden();
    });

    it('cada método llamado directo sobre un grupo o sesión de otra carrera da 403 y no escribe', function (string $component, string $method, Closure $arguments) {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();
        $tested = $component === SCHEDULE_EDITOR ? editorAs($this->other) : editorDrawerAs($this->other, $component);

        $tested->call($method, ...$arguments($this, $session))->assertForbidden();

        expect(editorSessionsOf($this->calcGroup))->toBe(['1 07:00-09:00 teoria']);
    })->with([
        'elegir' => [SCHEDULE_EDITOR, 'pick', fn ($test) => [$test->calcGroup->id]],
        'colocar' => [SCHEDULE_EDITOR, 'place', fn ($test) => [$test->calcGroup->id, 2, '07:00']],
        'abrir la sesión' => [SESSION_DRAWER, 'open', fn ($test, $session) => [$session->id]],
        'abrir el grupo' => [GROUP_DRAWER, 'open', fn ($test) => [$test->calcGroup->id]],
    ]);

    it('guardar o quitar una sesión ajena desde un cajón que abrió el admin da 403', function () {
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        foreach (['save', 'remove'] as $method) {
            $drawer = editorDrawerAs(User::factory()->admin()->create(), SESSION_DRAWER)->call('open', $session->id)->set('day', '2');
            $this->actingAs($this->other);
            $drawer->call($method)->assertForbidden();
        }

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

    it('asigna un salón desde el cajón de la sesión, el cajón vuelve a leer el validador y la semana lo muestra', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
        $room = Room::factory()->capacity(30)->create(['code' => 'A-7', 'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
        $session = GroupSession::factory()->for($this->calcGroup)->on(2, '09:00', '10:00')->create();
        $blockOf = fn (Testable $page) => domOf($page)->querySelector('[data-testid=week] [data-session="'.$session->id.'"]');
        $checksOf = fn (Testable $drawer): string => textOf(domOf($drawer)->querySelector('[aria-labelledby=session-checks-title]'));

        $page = editorAs($jefe);
        $drawer = editorDrawerAs($jefe, SESSION_DRAWER)->dispatch('open-session', id: $session->id)
            ->assertSee('Salón actual')
            ->assertSee('Salones sugeridos')
            ->assertSee('A-7')
            ->assertDontSee('Lo asigna el jefe');

        expect(textOf($blockOf($page)))->toContain('Sin salón')
            ->and($checksOf($drawer))->not->toContain('HC-05');

        $this->travel(1)->minute();
        Livewire::actingAs($jefe)->test('scheduling::components.session-room', ['sessionId' => $session->id])
            ->call('assign', $room->id)
            ->assertHasNoErrors()
            ->assertDispatched('session-room-saved', sessionId: $session->id);
        app()->forgetScopedInstances(); // otra petición: la Query del editor memoriza por petición

        // El grupo (35) no cabe en A-7 (30): se guarda y queda marcado con HC-05, en el cajón y en la semana.
        $drawer->dispatch('session-room-saved', sessionId: $session->id)
            ->assertSet('panel.room', 'A-7')
            ->assertSet('panel.updated_at', (string) $session->fresh()->updated_at->getTimestamp());
        $page->dispatch('session-room-saved', sessionId: $session->id);

        expect($session->fresh()->room_id)->toBe($room->id)
            ->and($checksOf($drawer))->toContain('HC-05')
            ->and(textOf($blockOf($page)))->toContain('ACF-0904', 'A-7', 'HC-05')->not->toContain('Sin salón');
    });
});

describe('Subdirección', function () {
    it('ve el semestre y los grupos sin editar: el motivo va una vez en la barra y las celdas no colocan', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $page = editorAs($subdireccion, ['carrera' => $this->isc->id])->assertSee('ACF-0904 · A')->call('pick', $this->calcGroup->id)->assertSee('Solo lectura');
        $dom = domOf($page);

        $cells = iterator_to_array($dom->querySelectorAll('[data-testid=week] [data-cell]'));

        expect(array_filter($cells, fn ($cell): bool => ! str_contains($cell->getAttribute('class'), 'pointer-events-none')))->toBe([])
            ->and($dom->querySelectorAll('[data-testid=week] button[data-cell]'))->toHaveCount(0)
            ->and($dom->querySelectorAll('[data-testid=week] [data-cell][data-fit=fits]')->length)->toBeGreaterThan(0)
            ->and($dom->querySelectorAll('[data-testid=edit-reason]'))->toHaveCount(1);

        $page->call('place', $this->calcGroup->id, 1, '07:00')->assertForbidden();
    });
});

describe('docente', function () {
    it('no entra a la ruta ni ve la entrada del menú', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($docente)->get(route('scheduling.editor'))->assertForbidden();

        expect(textOf(domOf($this->get('/'))->getElementById('nexus-menu')))->not->toContain('Horarios');
    });
});

describe('otro campus', function () {
    it('MS6: el coordinador de la carrera limitado a otro campus abre en el suyo; en el filtro elige este y ve sus grupos de solo lectura, con dónde sí edita, sin acomodarlos', function () {
        $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->isc->id])->create();
        onCampusPage(null);

        Livewire::actingAs($elsewhere)->test(SCHEDULE_EDITOR)->assertOk()->assertSet('campus', $this->otherCampus->code)
            ->assertDontSee('ACF-0904')->assertSee('no ofrece carreras');

        $page = Livewire::withQueryParams(['campus' => $this->campus->code, 'semestre' => '3'])->actingAs($elsewhere)->test(SCHEDULE_EDITOR)
            ->assertSee('ACF-0904 · A')
            ->call('pick', $this->calcGroup->id)
            ->assertSee('Solo lectura: solo puedes editar los grupos de C1.');

        $page->call('place', $this->calcGroup->id, 1, '07:00')->assertForbidden();
        expect($this->calcGroup->sessions()->count())->toBe(0);
    });

    it('en la página de su campus, el coordinador limitado a otro campus no ve los grupos de este: el campus de la página es un filtro', function () {
        $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->isc->id])->create();
        onCampusPage($this->otherCampus);

        Livewire::actingAs($elsewhere)->test(SCHEDULE_EDITOR)->call('place', $this->calcGroup->id, 1, '07:00')->assertNotFound();
    });
});

describe('modalidad', function () {
    it('MS6: el coordinador de la carrera limitado a otro campus ve la sesión, de solo lectura, y el motivo dice dónde sí edita', function () {
        $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->isc->id])->create();
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        editorDrawerAs($elsewhere, SESSION_DRAWER)->call('open', $session->id)
            ->assertSee('Solo lectura: solo puedes editar los grupos de C1.');
        editorDrawerAs($this->coordinator, SESSION_DRAWER)->call('open', $session->id)
            ->assertDontSee('Solo lectura');
    });

    it('M1: un semestre que mezcla modalidades sigue la elegida en el select «Modalidad»', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $ead = editorGroup($this->calculus, 'E', ['modality_id' => modalityOf('EAD')->id]);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

        $presencial = editorAs($jefe, ['carrera' => $this->isc->id, 'modalidad' => 'PRES'])
            ->assertSeeHtml('data-testid="modality-scope"');
        $distancia = editorAs($jefe, ['carrera' => $this->isc->id, 'modalidad' => 'EAD']);

        expect(array_column($presencial->viewData('groups'), 'id'))->toContain($this->calcGroup->id)->not->toContain($ead->id)
            ->and(array_column($distancia->viewData('groups'), 'id'))->toBe([$ead->id]);
    });

    it('cambiar de modalidad reabre la página en ella, en el mismo campus', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        editorAs($subdireccion, ['campus' => $this->campus->code, 'modalidad' => 'PRES'])
            ->set('modalidad', 'EAD')
            ->assertRedirect(route('scheduling.editor', ['modalidad' => 'EAD', 'campus' => $this->campus->code]));
    });

    it('otra modalidad: el coordinador de la carrera en EAD no ve los grupos presenciales aunque pida ?modalidad=PRES, ni los acomoda', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $ead = User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('EAD')->id, 'career_id' => $this->isc->id])->create();

        $page = editorAs($ead, ['carrera' => $this->isc->id, 'modalidad' => 'PRES'])->assertSet('modalidad', 'EAD')->assertDontSee('ACF-0904');

        expect($page->viewData('shownModality')->code)->toBe('EAD');
        $page->call('place', $this->calcGroup->id, 1, '07:00')->assertForbidden();
    });

    it('U2: el filtro de campus ofrece los campus de la modalidad, uno a la vez, con los grupos del semestre en cada uno; cada grupo lleva su etiqueta', function () {
        $dom = domOf(editorAs($this->coordinator, ['carrera' => $this->isc->id]));

        expect($dom->querySelector('[data-testid="campus-filter-todos"]'))->toBeNull()
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C2"]')))->toBe('2')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C1"]')))->toBe('0')
            ->and($dom->querySelector('[data-testid="campus-filter-C2"]')?->getAttribute('aria-pressed'))->toBe('true')
            ->and($dom->querySelectorAll('[data-testid="tray-campus"]'))->toHaveCount(2)
            ->and(textOf($dom->querySelector('[data-testid="editor-modality"]')))->toBe('Presencial');
    });

    it('MS4: con En línea activa y abierta junto a Presencial, abre en Presencial; elegir En línea lo dice en lugar de dar 403', function () {
        modalityOf('ENL')->update(['is_active' => true]);
        $admin = User::factory()->admin()->create();

        foreach (['ENL', 'PRES'] as $code) {
            TermOpening::query()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf($code)->id, 'opened_by' => $admin->id, 'opened_at' => now()]);
        }

        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        onCampusPage($this->campus);

        Livewire::withQueryParams(['semestre' => '3', 'carrera' => $this->isc->id])->actingAs($subdireccion)->test(SCHEDULE_EDITOR)
            ->assertOk()->assertSet('modalidad', 'PRES')->assertSee('ACF-0904 · A');
        Livewire::withQueryParams(['modalidad' => 'ENL'])->actingAs($subdireccion)->test(SCHEDULE_EDITOR)
            ->assertOk()
            ->assertSee('En línea no usa salones: sus grupos no se dan en un campus, y el editor acomoda las sesiones de un campus a la vez.')
            ->assertSeeHtml('data-testid="modality-scope"')
            ->set('modalidad', 'PRES')
            ->assertRedirect(route('scheduling.editor', ['modalidad' => 'PRES']));
    });

    it('MS30: una modalidad activa con perfil guardado inválido cuenta como sin salones y no rompe la de omisión', function () {
        $broken = Modality::factory()->create(['code' => 'A01', 'name' => 'Rota']);
        $broken->forceFill(['settings' => ['block_minutes' => 45]])->save();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        editorAs($subdireccion)->assertOk()->assertSet('modalidad', 'PRES');
    });

    it('fija la modalidad que resolvió: si después se abre otra antes por clave, la página no cambia de modalidad', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $page = editorAs($subdireccion)->assertSet('modalidad', 'EAD');

        TermOpening::query()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'opened_by' => $subdireccion->id, 'opened_at' => now()]);

        $page->call('clearSelection')->assertSet('modalidad', 'EAD');
    });

    it('?grupo= abre el campus y la modalidad del grupo, aunque la sesión recuerde otro campus', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        onCampusPage($this->otherCampus);

        Livewire::withQueryParams(['grupo' => $this->calcGroup->id])->actingAs($subdireccion)->test(SCHEDULE_EDITOR)
            ->assertSet('campus', $this->campus->code)
            ->assertSet('modalidad', 'PRES')
            ->assertSet('groupId', $this->calcGroup->id)
            ->assertSee('ACF-0904 · A');
    });

    it('HC-04: un semestre sin grupos pinta la semana con el perfil de la modalidad de la página, no con el de omisión', function () {
        modalityOf('PRES')->update(['settings' => [...ModalityProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);

        $page = editorAs($this->coordinator, ['carrera' => $this->isc->id, 'reticula' => $this->plan->id, 'semestre' => '1']);

        expect($page->viewData('groups'))->toBe([])
            ->and(array_column($page->viewData('grid')->days, 'day'))->toBe([1, 2, 3, 4, 5, 6]);
    });

    it('HC-04: la semana del editor y del cajón son las del perfil de la modalidad del grupo (el campus no tiene perfil, MS3)', function () {
        $this->calcGroup->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);
        $session = GroupSession::factory()->for($this->calcGroup)->on(1, '07:00', '09:00')->create();

        $page = editorAs($this->coordinator)->call('pick', $this->calcGroup->id);
        $drawer = editorDrawerAs($this->coordinator, SESSION_DRAWER)->call('open', $session->id);

        expect(array_column($page->viewData('grid')->days, 'day'))->toBe([1, 2, 3, 4, 5, 6])
            ->and(array_column($drawer->get('panel')['days'], 'id'))->toBe(['1', '2', '3', '4', '5', '6']);
    });
});

describe('contexto', function () {
    it('sin ningún campus activo donde trabaje, responde 403', function () {
        Campus::query()->update(['is_active' => false]);
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        Livewire::actingAs($subdireccion)->test(SCHEDULE_EDITOR)->assertForbidden();
    });

    it('AZ-02: Subdirección elige el campus en la página y cambiarlo la reabre en él, sin el grupo', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        Livewire::withQueryParams(['campus' => $this->otherCampus->code])->actingAs($subdireccion)->test(SCHEDULE_EDITOR)
            ->assertSee($this->otherCampus->name)
            ->assertSeeHtml('data-testid="campus-filter-'.$this->campus->code.'"')
            ->set('campus', $this->campus->code)
            ->assertRedirect(route('scheduling.editor', ['modalidad' => 'PRES', 'campus' => $this->campus->code]));
    });

    it('el menú lleva a «Horarios» en Académico a quien ve los horarios', function () {
        $this->actingAs($this->coordinator)->get(route('scheduling.editor'))->assertOk()->assertSee('Horarios');

        expect(textOf(domOf($this->get('/'))->getElementById('nexus-menu')))->toContain('Horarios');
    });
});

it('no dispara consultas de más al pintar, elegir, colocar ni abrir un cajón, y no crecen con los grupos del semestre', function () {
    // El recálculo del tablero de choques corre en la cola, no en la petición del editor (colocar sí incluye la clave
    // del grupo, AssignCodeOnScheduleChange, y la validación del grupo antes de colocar, para avisar lo que agrega).
    // Cada llamada es una petición nueva: la Query del editor es `scoped` y memoriza por petición.
    Queue::fake([RecomputeScheduleConflicts::class]);
    onCampusPage($this->campus);
    $this->actingAs($this->coordinator);
    $queries = function (Closure $action): int {
        app()->forgetScopedInstances();
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
    $drawer = editorDrawerAs($this->coordinator, SESSION_DRAWER);
    $openSession = $queries(fn () => $drawer->call('open', $this->calcGroup->sessions()->value('id')));
    $groupDrawer = editorDrawerAs($this->coordinator, GROUP_DRAWER);
    $openGroup = $queries(fn () => $groupDrawer->call('open', $this->calcGroup->id));

    // Cada petición lee una vez las modalidades activas para el alcance del coordinador (Actor, MS8), y los campus de la
    // modalidad y los grupos del semestre en cada uno para el filtro de campus (maqueta U2).
    expect($many)->toBe($few)
        ->and($many)->toBeLessThanOrEqual(34)
        ->and($pick)->toBeLessThanOrEqual(36)
        ->and($place)->toBeLessThanOrEqual(102)
        ->and($openSession)->toBeLessThanOrEqual(38) // con la sección del salón y sus sugerencias (tarea 3.7)
        ->and($openGroup)->toBeLessThanOrEqual(18); // con el perfil de su modalidad y los campus a los que se puede cambiar (M8)
});
