<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;

/*
| La propuesta con la proyección de Nexus en el navegador (tarea 5.6, opción C «Revisión de la proyección»): el coordinador
| corrige un esperado y pide un grupo más en la tabla (criterio de la 5.6); la fila queda marcada para revisar. Después
| abre «Revisar proyección» y, en la materia cuya proyección cambió, usa lo que sugiere ahora Nexus.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $term->id, 'career_id' => $career->id]);

    $row = function (string $code, string $name, int $semester, array $item, array $forecast) use ($campus, $department): ProposalItem {
        $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id]);
        DemandForecast::factory()->create([
            'term_id' => $this->proposal->term_id,
            'modality_id' => $this->proposal->modality_id,
            'career_id' => $this->proposal->career_id,
            'subject_id' => $subject->id,
            'campus_id' => $campus->id,
            ...$forecast,
        ]);

        return ProposalItem::factory()->create([
            'career_proposal_id' => $this->proposal->id,
            'campus_id' => $campus->id,
            'subject_id' => $subject->id,
            'semester' => $semester,
            'eligible_students' => 60,
            'suggested_expected_students' => 50,
            'suggested_groups' => 2,
            ...$item,
        ]);
    };

    $this->calculus = $row('ACF-0901', 'Cálculo Diferencial', 1, ['expected_students' => 50, 'requested_groups' => 2], ['expected_students' => 50, 'suggested_groups' => 2]);
    $this->integral = $row('ACF-0902', 'Cálculo Integral', 2, ['expected_students' => 40, 'requested_groups' => 2, 'captured_at' => now()], ['expected_students' => 90, 'suggested_groups' => 3]);

    User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(['username' => '19110003']);
});

it('el coordinador corrige un esperado, pide un grupo más y usa la proyección nueva en «Revisar proyección»', function () {
    $setCell = fn (string $field, string $value): string => "(() => { const input = document.querySelector('[data-testid=row-ACF-0901] [data-field={$field}]'); input.value = '{$value}'; input.dispatchEvent(new Event('change', { bubbles: true })); })()";

    $page = visit('/login')
        ->fill('username', '19110003')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal?vista=tabla')
        ->assertSee('Proyección de Nexus del')
        ->assertPresent('@recalc-alert')
        ->assertPresent('[data-testid=row-ACF-0901] [data-forecast-mark=suggested]');

    $page->script($setCell('expected_students', '55'));
    $page->assertPresent('[data-testid=row-ACF-0901] [data-forecast-mark=captured]');
    $page->script($setCell('requested_groups', '3'));

    $page->assertPresent('@review-chip-ACF-0901')
        ->assertSeeIn('@review-chip-ACF-0901', 'Revisar: se aparta del sugerido')
        ->click('@tab-review')
        ->assertQueryStringHas('vista', 'revisar')
        ->assertSeeIn('@review-count', '2')
        ->assertPresent('@review-card-'.$this->integral->id)
        ->click('@review-use-'.$this->integral->id)
        ->assertMissing('@review-card-'.$this->integral->id)
        ->assertSeeIn('@review-count', '1')
        ->assertNoJavaScriptErrors();

    expect($this->calculus->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 55, 'requested_groups' => 3])
        ->and($this->integral->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 90, 'requested_groups' => 3])
        ->and($this->integral->fresh()->reviewed_at)->not->toBeNull();
});
