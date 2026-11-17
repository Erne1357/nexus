<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| `solver_runs` (tarea 6.2; docs/dominio/modelo-de-datos.md, «Solver»): cada corrida del motor, de la carga de un
| departamento en una modalidad o de una carrera (exploración del coordinador), con su motor, su estado, su entrada y su
| salida (en la fila o en un archivo bajo `storage/solver`), métricas, autor y aplicación. Sin campus: una corrida abarca
| todos los campus de la carga (decisión del usuario, 17 de noviembre de 2026).
*/

it('pertenece a su periodo, modalidad, departamento y autor', function () {
    $run = SolverRun::factory()->create();

    expect($run->term)->toBeInstanceOf(Term::class)
        ->and($run->modality)->toBeInstanceOf(Modality::class)
        ->and($run->department)->toBeInstanceOf(Department::class)
        ->and($run->career)->toBeNull()
        ->and($run->creator)->toBeInstanceOf(User::class)
        ->and($run->scope_kind)->toBe(RunScope::Department)
        ->and($run->status)->toBe(SolverRunStatus::Queued)
        ->and($run->input)->toBeArray();
});

it('una corrida de carrera lleva la carrera y no el departamento', function () {
    $run = SolverRun::factory()->forCareer()->create();

    expect($run->career)->toBeInstanceOf(Career::class)
        ->and($run->department_id)->toBeNull()
        ->and($run->scope_kind)->toBe(RunScope::Career);
});

it('el alcance pide exactamente su llave: departamento o carrera', function (array $attributes) {
    expect(fn () => SolverRun::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'departamento sin departamento' => [['department_id' => null]],
    'departamento con carrera' => [fn () => ['career_id' => Career::factory()->create()->id]],
    'carrera sin carrera' => [['scope_kind' => RunScope::Career->value, 'career_id' => null, 'department_id' => null]],
]);

it('rechaza un estado o un alcance que no existe', function (string $column, string $value) {
    $row = SolverRun::factory()->make()->getAttributes();

    expect(fn () => DB::table('solver_runs')->insert([...$row, 'id' => (string) Str::uuid7(), $column => $value, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'estado' => ['status', 'perdido'],
    'alcance' => ['scope_kind', 'campus'],
]);

it('guarda la entrada en un archivo cuando es grande y la fila conserva la ruta', function () {
    $run = SolverRun::factory()->create(['input' => null, 'input_path' => 'solver/runs/abc/input.json']);

    expect($run->fresh()->input)->toBeNull()
        ->and($run->fresh()->input_path)->toBe('solver/runs/abc/input.json');
});
