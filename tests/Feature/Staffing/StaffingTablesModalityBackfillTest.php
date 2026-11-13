<?php

use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Ampliar (ronda 2 de la carga por modalidad, ADR-0025): `staffing_rankings`, `group_teacher_assignments` y
| `change_requests` reciben `modality_id`, obligatoria y copiada de su grupo, como ya copian el periodo y el campus.
| El historial de asignaciones es de solo agregar: la migración apaga su trigger solo para llenar la columna, dentro de
| su transacción, y lo deja como estaba. Ningún lector cambia todavía. Se prueba sobre la forma vieja: el `down()` de
| la migración que después quitó el campus (2026_11_12_210801), el `down()` de esta, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    (require base_path('app/Modules/Staffing/Database/Migrations/2026_11_12_210801_drop_campus_from_staffing_tables.php'))->down();
    $this->migration = require base_path('app/Modules/Staffing/Database/Migrations/2026_11_12_210403_add_modality_to_staffing_tables.php');
    $this->migration->down();
    $this->user = User::factory()->create();
    $this->inC1 = Group::factory()->create(['campus_id' => Campus::factory()->create(['code' => 'C1'])->id]);
    $this->inC2 = Group::factory()->create(['campus_id' => Campus::factory()->create(['code' => 'C2'])->id]);
    $this->inEad = Group::factory()->create(['campus_id' => Campus::factory()->inactive()->create(['code' => 'EAD', 'modality' => CampusModality::Mixta])->id]);
});

/**
 * Inserta una fila con la forma de antes de la ampliación (sin `modality_id`), con el periodo y el campus de su grupo,
 * y devuelve su id.
 *
 * @param  array<string, mixed>  $attributes
 */
function staffingRowBeforeModality(string $table, Group $group, array $attributes): string
{
    $id = (string) Str::uuid7();

    DB::table($table)->insert([
        'id' => $id,
        'term_id' => $group->term_id,
        'campus_id' => $group->campus_id,
        'group_id' => $group->id,
        ...$attributes,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function staffingRankingBeforeModality(Group $group): string
{
    return staffingRowBeforeModality('staffing_rankings', $group, [
        'teacher_id' => Teacher::factory()->create()->id, 'rank' => 1, 'score' => 10, 'breakdown' => '{}', 'computed_at' => now(),
    ]);
}

function staffingAssignmentBeforeModality(object $test, Group $group, bool $replaced = false): string
{
    return staffingRowBeforeModality('group_teacher_assignments', $group, [
        'teacher_id' => Teacher::factory()->create()->id,
        'assigned_by' => $test->user->id,
        'assigned_at' => now()->subDay(),
        'ranking_position_at_assignment' => 1,
        'ranking_size_at_assignment' => 3,
        'replaced_at' => $replaced ? now() : null,
        'replaced_by' => $replaced ? $test->user->id : null,
    ]);
}

function staffingChangeRequestBeforeModality(object $test, Group $group): string
{
    return staffingRowBeforeModality('change_requests', $group, [
        'department_id' => $group->department_id,
        'requested_by' => $test->user->id,
        'kind' => 'schedule',
        'current' => '[]',
        'proposed' => '{"description": "Los martes de 7 a 9"}',
        'reason' => 'Empalme con otra clase.',
        'status' => 'pending',
    ]);
}

/**
 * La clave de la modalidad del catálogo de cada fila, por llave.
 *
 * @param  array<string, string>  $ids
 * @return array<string, ?string>
 */
function staffingModalityCodes(string $table, array $ids): array
{
    return array_map(
        fn (string $id): ?string => DB::table($table)->join('modalities', 'modalities.id', '=', "{$table}.modality_id")->where("{$table}.id", $id)->value('modalities.code'),
        $ids,
    );
}

it('copia la modalidad del grupo en rankings, asignaciones y solicitudes: los de C1 y C2 quedan en PRES', function () {
    $rankings = ['C1' => staffingRankingBeforeModality($this->inC1), 'C2' => staffingRankingBeforeModality($this->inC2), 'EAD' => staffingRankingBeforeModality($this->inEad)];
    $assignments = [
        'C1 reemplazada' => staffingAssignmentBeforeModality($this, $this->inC1, replaced: true),
        'C1 vigente' => staffingAssignmentBeforeModality($this, $this->inC1),
        'C2 vigente' => staffingAssignmentBeforeModality($this, $this->inC2),
        'EAD vigente' => staffingAssignmentBeforeModality($this, $this->inEad),
    ];
    $requests = ['C1' => staffingChangeRequestBeforeModality($this, $this->inC1), 'C2' => staffingChangeRequestBeforeModality($this, $this->inC2)];

    $this->migration->up();

    expect(staffingModalityCodes('staffing_rankings', $rankings))->toBe(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD'])
        ->and(staffingModalityCodes('group_teacher_assignments', $assignments))->toBe(['C1 reemplazada' => 'PRES', 'C1 vigente' => 'PRES', 'C2 vigente' => 'PRES', 'EAD vigente' => 'EAD'])
        ->and(staffingModalityCodes('change_requests', $requests))->toBe(['C1' => 'PRES', 'C2' => 'PRES']);

    foreach (['staffing_rankings', 'group_teacher_assignments', 'change_requests'] as $table) {
        expect(DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', 'modality_id')->value('is_nullable'))->toBe('NO');
    }
});

it('HC-16: el historial de asignaciones sigue siendo de solo agregar después de llenarlo', function () {
    $replaced = staffingAssignmentBeforeModality($this, $this->inC1, replaced: true);
    $current = staffingAssignmentBeforeModality($this, $this->inC1);

    $this->migration->up();

    $ead = Modality::query()->where('code', 'EAD')->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $current)->update(['modality_id' => $ead])))
        ->toThrow(QueryException::class, 'solo agregar')
        ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $replaced)->update(['reason' => 'Otro motivo'])))
        ->toThrow(QueryException::class, 'solo agregar')
        ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $current)->delete()))
        ->toThrow(QueryException::class, 'solo agregar');

    DB::table('group_teacher_assignments')->where('id', $current)->update(['replaced_at' => now(), 'replaced_by' => $this->user->id]);

    expect(DB::table('group_teacher_assignments')->where('id', $current)->value('replaced_at'))->not->toBeNull();
});
