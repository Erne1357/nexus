<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\AssignmentLabel;
use App\Modules\Core\Queries\AssignmentLabels;
use Illuminate\Support\Facades\DB;

it('nombra el alcance de cada nombramiento con una consulta por dimensión', function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $isc = Career::factory()->create(['code' => 'ISC']);
    $systems = Department::factory()->create(['name' => 'Sistemas y Computación']);

    $user = User::factory()->create();
    $coordinator = RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $c2->id, 'career_id' => $isc->id]);
    $jpd = RoleAssignment::factory()->for($user)->create(['role' => Role::JefeProyectoDocente, 'campus_id' => $c2->id, 'org_unit_id' => $systems->id]);
    $allCampuses = RoleAssignment::factory()->for($user)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $systems->id, 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31']);
    $sub = RoleAssignment::factory()->for($user)->create(['role' => Role::Subdireccion, 'org_unit_id' => app(OrgTree::class)->idOf(OrgTree::ACADEMIC)]);
    $teacher = RoleAssignment::factory()->for($user)->create(['role' => Role::Docente]);

    $assignments = RoleAssignment::query()->whereKey([$coordinator->id, $jpd->id, $allCampuses->id, $sub->id, $teacher->id])->get();

    DB::enableQueryLog();
    $labels = app(AssignmentLabels::class)->describe($assignments);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(4)
        ->and($labels)->toHaveCount(5)
        ->and($labels[$coordinator->id])->toBeInstanceOf(AssignmentLabel::class)
        ->and($labels[$coordinator->id]->title)->toBe('Coordinador de carrera')
        ->and($labels[$coordinator->id]->summary)->toBe('Coordinador de carrera · ISC · Presencial · Campus II')
        ->and($labels[$coordinator->id]->chips)->toBe([
            ['label' => 'Carrera', 'value' => 'ISC'],
            ['label' => 'Modalidad', 'value' => 'Presencial'],
            ['label' => 'Campus', 'value' => 'Campus II'],
        ])
        ->and($labels[$jpd->id]->summary)->toBe('Jefe de proyecto docente · Sistemas y Computación · Presencial · Campus II')
        ->and($labels[$jpd->id]->chips)->toBe([
            ['label' => 'Depto.', 'value' => 'Sistemas y Computación'],
            ['label' => 'Modalidad', 'value' => 'Presencial'],
            ['label' => 'Campus', 'value' => 'Campus II'],
        ])
        ->and($labels[$allCampuses->id]->summary)->toBe('Jefe de proyecto docente · Sistemas y Computación · Presencial · Todos los campus')
        ->and($labels[$sub->id]->summary)->toBe('Subdirección Académica')
        ->and($labels[$sub->id]->chips)->toBe([['label' => 'Unidad', 'value' => 'Subdirección Académica']])
        ->and($labels[$teacher->id]->summary)->toBe('Docente')
        ->and($labels[$teacher->id]->chips)->toBe([]);
});

it('one() describe un solo nombramiento, también si su campus o su modalidad están borrados', function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $isc = Career::factory()->create(['code' => 'ISC']);
    $assignment = RoleAssignment::factory()->create(['role' => Role::Coordinador, 'campus_id' => $c2->id, 'career_id' => $isc->id]);
    $c2->delete();
    modalityOf('PRES')->delete();

    expect(app(AssignmentLabels::class)->one($assignment)->summary)->toBe('Coordinador de carrera · ISC · Presencial · Campus II');
});
