<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Cambiar (ronda 2 de la carga por modalidad, M1 y M8): `staffing_rankings`, `group_teacher_assignments` y
| `change_requests` ya no copian el campus de su grupo; lo leen del grupo, así que un grupo que cambia de campus no
| deja copias viejas. Siguen copiando el periodo y la modalidad. down() vuelve a poner la columna con el campus de cada
| grupo, también en el historial de asignaciones, que sigue siendo de solo agregar.
*/

/**
 * Las tablas de Staffing que dejaron de copiar el campus de su grupo.
 *
 * @return list<string>
 */
function staffingTablesWithoutCampus(): array
{
    return ['staffing_rankings', 'group_teacher_assignments', 'change_requests'];
}

beforeEach(function () {
    $this->migration = require base_path('app/Modules/Staffing/Database/Migrations/2026_11_12_210801_drop_campus_from_staffing_tables.php');
});

it('M8, MS9: rankings, asignaciones y solicitudes no guardan el campus: lo leen de su grupo', function () {
    foreach (staffingTablesWithoutCampus() as $table) {
        expect(Schema::hasColumn($table, 'campus_id'))->toBeFalse("{$table}.campus_id")
            ->and(Schema::hasColumn($table, 'modality_id'))->toBeTrue("{$table}.modality_id")
            ->and(Schema::hasColumn($table, 'term_id'))->toBeTrue("{$table}.term_id");
    }
});

it('down() vuelve a copiar el campus del grupo en cada fila, también en el historial de solo agregar, y up() lo quita otra vez', function () {
    $c1 = Group::factory()->create(['campus_id' => Campus::factory()->create(['code' => 'C1'])->id]);
    $c2 = Group::factory()->create(['campus_id' => Campus::factory()->create(['code' => 'C2'])->id]);
    $ranking = StaffingRanking::factory()->create(['group_id' => $c1->id]);
    $replaced = GroupTeacherAssignment::factory()->replaced()->create(['group_id' => $c2->id]);
    $current = GroupTeacherAssignment::factory()->create(['group_id' => $c2->id]);
    $request = ChangeRequest::factory()->forGroup($c1)->pending()->create();

    $this->migration->down();

    foreach (staffingTablesWithoutCampus() as $table) {
        expect(DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', 'campus_id')->value('is_nullable'))->toBe('NO', "{$table}.campus_id");
    }

    expect(DB::table('staffing_rankings')->where('id', $ranking->id)->value('campus_id'))->toBe($c1->campus_id)
        ->and(DB::table('group_teacher_assignments')->whereIn('id', [$replaced->id, $current->id])->pluck('campus_id')->unique()->all())->toBe([$c2->campus_id])
        ->and(DB::table('change_requests')->where('id', $request->id)->value('campus_id'))->toBe($c1->campus_id)
        ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $current->id)->update(['campus_id' => $c1->campus_id])))
        ->toThrow(QueryException::class, 'solo agregar');

    $this->migration->up();

    foreach (staffingTablesWithoutCampus() as $table) {
        expect(Schema::hasColumn($table, 'campus_id'))->toBeFalse("{$table}.campus_id");
    }

    expect(StaffingRanking::query()->whereKey($ranking->id)->exists())->toBeTrue()
        ->and(GroupTeacherAssignment::query()->whereKey([$replaced->id, $current->id])->count())->toBe(2)
        ->and(ChangeRequest::query()->whereKey($request->id)->exists())->toBeTrue();
});

it('HC-16: sin la columna, el historial de asignaciones sigue siendo de solo agregar', function () {
    $assignment = GroupTeacherAssignment::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['reason' => 'Otro motivo'])))
        ->toThrow(QueryException::class, 'solo agregar')
        ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->delete()))
        ->toThrow(QueryException::class, 'solo agregar');

    DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['replaced_at' => now()->addMinute(), 'replaced_by' => User::factory()->create()->id]);

    expect($assignment->fresh()->replaced_at)->not->toBeNull();
});
