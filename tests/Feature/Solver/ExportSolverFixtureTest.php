<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Contract\SolverInput;
use Illuminate\Support\Facades\File;

/*
| `solver:export-fixture` (tarea 6.11): escribe la entrada de una carga como fixture de tests/Fixtures/solver/v1 con
| todas las llaves cambiadas por fichas. El archivo no se escribe de verdad: la prueba lo atrapa al pasar por File.
*/

/**
 * Un grupo presencial de la carrera en el campus, semestre 1, de 30 lugares.
 *
 * @param  array<string, mixed>  $attributes
 */
function exportFixtureGroup(Term $term, Campus $campus, Subject $subject, Career $career, StudyPlan $plan, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id, 'subject_id' => $subject->id,
        'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'semester' => 1, 'capacity' => 30, ...$attributes,
    ]);
}

it('anonimiza el grupo de cada préstamo y la clave `combination:` con la misma ficha de plan que su grupo, y deja un contrato válido', function () {
    $term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18']);
    $campus = Campus::factory()->create(['code' => 'S1']);
    $sistemas = Department::factory()->create(['code' => 'SIS']);
    $basicas = Department::factory()->create(['code' => 'BAS']);
    $career = Career::factory()->create(['department_id' => $sistemas->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
    $prog = Subject::factory()->create(['department_id' => $sistemas->id, 'code' => 'SCC-1010', 'theory_hours' => 2, 'practice_hours' => 0]);
    $calc = Subject::factory()->create(['department_id' => $basicas->id, 'code' => 'AEF-1041', 'theory_hours' => 2, 'practice_hours' => 0]);
    $owned = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'department_id' => $basicas->id, 'capacity' => 40]);

    $movable = exportFixtureGroup($term, $campus, $prog, $career, $plan);
    $calcA = exportFixtureGroup($term, $campus, $calc, $career, $plan, ['code' => 'A']);
    exportFixtureGroup($term, $campus, $calc, $career, $plan, ['code' => 'B']);
    GroupSession::factory()->for($movable)->inRoom($owned)->on(4, '07:00', '09:00')->create();
    GroupSession::factory()->for($calcA)->on(1, '07:00', '09:00')->create();
    InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $term->id, 'campus_id' => $campus->id, 'group_id' => $movable->id, 'room_id' => $owned->id,
        'from_department_id' => $basicas->id, 'to_department_id' => $sistemas->id, 'day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00',
    ]);

    $written = null;
    File::partialMock()->shouldReceive('ensureDirectoryExists')->andReturnNull();
    File::partialMock()->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$written): int {
        $written = $contents;

        return strlen($contents);
    });

    $this->artisan('solver:export-fixture', ['term' => '20271', 'modality' => 'PRES', 'department_code' => 'SIS', 'name' => 'prueba-exportar'])->assertSuccessful();

    $data = json_decode((string) $written, true, flags: JSON_THROW_ON_ERROR);
    $groups = collect($data['groups'])->keyBy('id');
    $mine = $groups->firstWhere('movable', true);

    expect($data['agreements'])->toHaveCount(1)
        ->and($data['agreements'][0]['group_id'])->toBe($mine['id'])
        ->and($mine['cohorts'])->toBe(["plan:{$mine['study_plan_id']}:1:S1", "combination:{$mine['study_plan_id']}:1:S1"])
        ->and($groups->filter(fn (array $group): bool => in_array("combination:{$mine['study_plan_id']}:1:S1", $group['cohorts'], true)))->toHaveCount(2)
        ->and((string) $written)->not->toMatch('/[0-9a-f]{8}-[0-9a-f]{4}-/')
        ->and(SolverInput::fromArray($data)->agreements[0]->groupId)->toBe($mine['id']);
});

it('con --path escribe la entrada en esa carpeta (storage/app), nunca en tests/Fixtures', function () {
    $term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18']);
    $campus = Campus::factory()->create(['code' => 'S1']);
    $sistemas = Department::factory()->create(['code' => 'SIS']);
    $career = Career::factory()->create(['department_id' => $sistemas->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    exportFixtureGroup($term, $campus, Subject::factory()->create(['department_id' => $sistemas->id, 'code' => 'SCC-1010', 'theory_hours' => 2, 'practice_hours' => 0]), $career, $plan);

    $paths = [];
    File::partialMock()->shouldReceive('ensureDirectoryExists')->andReturnNull();
    File::partialMock()->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$paths): int {
        $paths[] = $path;

        return strlen($contents);
    });

    $this->artisan('solver:export-fixture', ['term' => '20271', 'modality' => 'PRES', 'department_code' => 'SIS', 'name' => 'medida-real', '--path' => 'storage/app/solver-benchmark'])->assertSuccessful();

    expect($paths)->toBe([base_path('storage/app/solver-benchmark/medida-real/input.json')]);
});

it('--path no acepta una carpeta fuera del proyecto ni dentro de tests', function (string $path) {
    $this->artisan('solver:export-fixture', ['term' => '20271', 'modality' => 'PRES', 'department_code' => 'SIS', 'name' => 'x', '--path' => $path])
        ->expectsOutputToContain('La carpeta de --path debe estar dentro de storage/app.')
        ->assertExitCode(2);
})->with(['/tmp/fuera', 'tests/Fixtures/solver/v1', 'storage/app/../../tests']);
