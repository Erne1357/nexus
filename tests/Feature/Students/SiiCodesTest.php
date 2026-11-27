<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\Academic\Queries\SubjectAliasQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentRecordQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| D8-bis (confirmado el 22 de noviembre de 2026): en la retícula y el kárdex cada materia muestra como clave principal
| la del SII de la retícula del plan y, en pequeño, la del TecNM; sin alias en ese plan, o igual a la clave, solo la de
| Nexus. La identidad interna (data-subject, búsquedas, URLs) no cambia.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::factory()->create();
    $this->plan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 100]);
    $this->otherPlan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224-ISC', 'total_credits' => 100]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-2301', 'name' => 'Cálculo Vectorial', 'credits' => 5]);
    $this->physics = Subject::factory()->create(['code' => 'ACF-2302', 'name' => 'Física General', 'credits' => 5]);
    $this->same = Subject::factory()->create(['code' => '3EB', 'name' => 'Taller de Ética', 'credits' => 5]);
    foreach ([$this->calculus, $this->physics, $this->same] as $subject) {
        placeInPlan($this->plan, $subject);
    }
    SubjectAlias::create(['subject_id' => $this->calculus->id, 'study_plan_id' => $this->plan->id, 'code' => '1EA']);
    SubjectAlias::create(['subject_id' => $this->calculus->id, 'study_plan_id' => $this->otherPlan->id, 'code' => '1AE']);
    SubjectAlias::create(['subject_id' => $this->same->id, 'study_plan_id' => $this->plan->id, 'code' => '3EB']);
    $this->base = Subject::factory()->create(['code' => 'ACF-2303', 'name' => 'Álgebra Base', 'credits' => 5]);
    placeInPlan($this->plan, $this->base);
    SubjectAlias::create(['subject_id' => $this->base->id, 'study_plan_id' => $this->plan->id, 'code' => '2VC']);
    seriate($this->plan, $this->physics, $this->base);
    $this->student = Student::factory()->create(['career_id' => $this->career->id, 'study_plan_id' => $this->plan->id, 'campus_id' => $this->campus->id, 'current_semester' => 1]);
    KardexRecord::factory()->for($this->student)->for($this->calculus)->create(['term_code' => '20253', 'grade' => 88]);
    $this->staff = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
});

it('D8-bis: la consulta trae la clave del SII del plan, sin mezclar otro plan ni las claves iguales a la de Nexus', function () {
    $query = new SubjectAliasQuery;

    expect($query->forPlan($this->plan->id))->toEqual([$this->calculus->id => '1EA', $this->base->id => '2VC'])
        ->and($query->forPlan($this->otherPlan->id))->toBe([$this->calculus->id => '1AE'])
        ->and($query->forPlans([$this->plan->id, $this->otherPlan->id]))->toHaveCount(2)
        ->and($query->forPlan(StudyPlan::factory()->create()->id))->toBe([])
        ->and(SubjectAliasQuery::display('ACF-2301', '1EA'))->toBe(['1EA', 'ACF-2301'])
        ->and(SubjectAliasQuery::display('ACF-2302', null))->toBe(['ACF-2302', null])
        ->and(SubjectAliasQuery::display('3EB', '3EB'))->toBe(['3EB', null]);
});

it('D8-bis: la ficha del alumno lleva una sola consulta de alias por plan, sin importar las materias', function () {
    $record = null;
    DB::enableQueryLog();
    $record = app(StudentRecordQuery::class)->forStudent($this->student);
    $aliasQueries = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'subject_aliases'))->count();
    DB::disableQueryLog();

    expect($aliasQueries)->toBe(1)
        ->and($record->siiCodes)->toEqual([$this->calculus->id => '1EA', $this->base->id => '2VC'])
        ->and($record->codesFor($this->calculus->id, 'ACF-2301'))->toBe(['1EA', 'ACF-2301'])
        ->and($record->codesFor($this->physics->id, 'ACF-2302'))->toBe(['ACF-2302', null]);
});

it('D8-bis: la retícula y el kárdex del alumno muestran 1EA principal y ACF-2301 debajo, y su identidad sigue siendo la de Nexus', function () {
    $page = Livewire::actingAs($this->staff)->withQueryParams(['tab' => 'reticula'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('data-subject="ACF-2301"')
        ->assertSeeHtml('shrink-0">1EA</span>')
        ->assertSeeHtml('data-secondary-code>ACF-2301</span>')
        ->assertSee('1EA')
        ->assertSee('ACF-2302');

    expect($page->html())->not->toContain('data-secondary-code>ACF-2302')->not->toContain('data-secondary-code>3EB')->not->toContain('1AE');

    Livewire::actingAs($this->staff)->withQueryParams(['tab' => 'kardex'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('data-testid="student-kardex"')
        ->assertSee('1EA')
        ->assertSeeHtml('data-secondary-code>ACF-2301</span>');
});

it('D8-bis: el «Pide» de la retícula usa la misma clave principal que el mosaico del requisito', function () {
    $page = Livewire::actingAs($this->staff)->withQueryParams(['tab' => 'reticula'])->test('students::pages.student', ['student' => $this->student->id]);

    expect($page->html())->toContain('Pide <span class="font-mono font-semibold">2VC</span>')->not->toContain('Pide <span class="font-mono font-semibold">ACF-2303</span>')
        ->toContain('data-label="1EA"');

    $admin = User::factory()->withRole(Role::Admin)->create();
    expect(Livewire::actingAs($admin)->test('academic::pages.plan', ['plan' => $this->plan->id])->html())
        ->toMatch('/Pide <span[^>]*>2VC<\/span>/');
});

it('D8-bis: la retícula del plan en catálogos también la muestra', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $html = Livewire::actingAs($admin)->test('academic::pages.plan', ['plan' => $this->plan->id])->html();

    expect($html)->toContain('data-secondary-code>ACF-2301</span>')->toContain('1EA')->not->toContain('data-secondary-code>ACF-2302');
});
