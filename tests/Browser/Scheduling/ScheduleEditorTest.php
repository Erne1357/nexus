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
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/*
| Editor de horarios en el navegador (tarea 3.5, maqueta A): el coordinador entra por el menú, elige el semestre,
| acomoda las horas de tres grupos tocando las celdas donde caben (el último con el teclado: flechas, Enter y Esc) y
| queda con «0 choques»; pasa del cajón de una sesión al de su grupo y, al cerrarlo, el foco regresa a la sesión; en
| celular cambia de día.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $department->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->campus->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224', 'is_current' => true]);
    AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $department->id]);

    $this->groups = [];

    foreach ([['ACF-0904', 'Cálculo Vectorial', 3], ['AED-1026', 'Estructura de Datos', 3], ['SCC-1005', 'Cultura Empresarial', 3], ['ACF-0901', 'Cálculo Diferencial', 1]] as [$code, $name, $semester]) {
        $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0]);
        placeInPlan($this->plan, $subject, $semester);
        $this->groups[$code] = Group::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'owner_career_id' => $this->career->id,
            'study_plan_id' => $this->plan->id, 'semester' => $semester, 'code' => 'A',
        ]);
    }

    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create(['username' => '19110005']);
});

it('el coordinador entra por el menú, acomoda las horas de tres grupos del semestre sin choques', function () {
    $page = visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[href$="/scheduling/editor"]')
        ->waitForText('Grupos del semestre')
        ->assertSee('ACF-0901 · A')
        ->select('@editor-semester', '3')
        ->waitForText('AED-1026 · A');

    foreach ([['ACF-0904', ['1|07:00', '1|08:00']], ['AED-1026', ['1|09:00', '1|10:00']]] as [$code, $cells]) {
        $page->click('[data-group="'.$this->groups[$code]->id.'"]')
            ->assertSeeIn('@selection-bar', "{$code} · A")
            ->click('[data-testid=week] [data-cell="'.$cells[0].'"]')
            ->assertSeeIn('@selection-bar', '1 de 2 h')
            ->click('[data-testid=week] [data-cell="'.$cells[1].'"]')
            ->assertSeeIn('@selection-bar', '2 de 2 h');
    }

    $page->click('[data-group="'.$this->groups['SCC-1005']->id.'"]')
        ->assertSeeIn('@selection-bar', 'SCC-1005 · A')
        ->keys('[data-testid=week] [data-cell="2|07:00"]', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.cell', '2|08:00')
        ->keys('[data-testid=week] [data-cell="2|08:00"]', 'Enter')
        ->assertSeeIn('@selection-bar', '1 de 2 h')
        ->keys('[data-testid=week] [data-cell="2|07:00"]', 'Enter')
        ->assertSeeIn('@selection-bar', '2 de 2 h')
        ->keys('[data-testid=week] [aria-label^="SCC-1005 · A"]', 'Escape')
        ->assertMissing('@selection-bar')
        ->assertSeeIn('@hard-count', '0 choques')
        ->assertSee('Sin choques en este semestre');

    // Los cajones: de la sesión al grupo no regresa el foco; al cerrar el último, regresa a la sesión que lo abrió.
    $page->click('[data-testid=week] [aria-label^="ACF-0904 · A"]')
        ->assertSeeIn('@session-drawer', 'Sesión de ACF-0904 · A')
        ->click('Ver el grupo')
        ->assertSeeIn('@group-drawer', 'Cupos por carrera')
        ->assertScript("document.activeElement?.getAttribute('aria-label')?.startsWith('ACF-0904 · A') === true", false)
        ->keys('[data-testid=group-drawer] h2', 'Escape')
        ->assertScript("document.activeElement?.getAttribute('aria-label')?.startsWith('ACF-0904 · A') === true", true);

    // En celular, cambiar de día es del navegador.
    $page->resize(390, 900)
        ->click('[data-testid=day-list] button[aria-label^="Martes"]')
        ->assertSeeIn('[data-testid=day-list] [data-day="2"]', 'SCC-1005 · A')
        ->assertNoJavaScriptErrors();

    $sessions = fn (string $code): array => GroupSession::query()->where('group_id', $this->groups[$code]->id)->get()
        ->map(fn (GroupSession $session): string => $session->day.' '.substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5))->all();

    expect($sessions('ACF-0904'))->toBe(['1 07:00-09:00'])
        ->and($sessions('AED-1026'))->toBe(['1 09:00-11:00'])
        ->and($sessions('SCC-1005'))->toBe(['2 07:00-09:00']);
});
