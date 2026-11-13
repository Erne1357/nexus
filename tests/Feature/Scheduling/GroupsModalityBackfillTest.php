<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Ampliar (ronda 2 de la carga por modalidad, ADR-0025): `groups` y `schedule_conflicts` reciben `modality_id`,
| obligatoria y llena con la modalidad del catálogo de su campus (el puente `campuses.modality_id`); `group_sessions` la
| copia de su grupo con el mismo trigger que copia el periodo, el campus y el docente, y otro la propaga cuando el grupo
| cambia de modalidad. Ningún lector cambia todavía. Si un grupo o un choque es de un campus sin modalidad del catálogo,
| se detiene, nombra el campus y no deja nada. Se prueba sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
    $this->migration = require base_path('app/Modules/Scheduling/Database/Migrations/2026_11_12_210402_add_modality_to_groups.php');
    $this->migration->down();
    $this->term = Term::factory()->create();
    $this->c1 = campusBeforeContraction('C1');
    $this->c2 = campusBeforeContraction('C2');
    $this->ead = campusBeforeContraction('EAD', 'mixta', active: false);
    $this->subject = Subject::factory()->create();
    $this->plan = StudyPlan::factory()->create();
});

/**
 * Inserta un grupo con la forma de antes de la ampliación (sin `modality_id`) y devuelve su id.
 */
function schedulingGroupBeforeModality(object $test, Campus $campus): string
{
    $id = (string) Str::uuid7();

    DB::table('groups')->insert([
        'id' => $id,
        'term_id' => $test->term->id,
        'campus_id' => $campus->id,
        'subject_id' => $test->subject->id,
        'department_id' => $test->subject->department_id,
        'owner_career_id' => $test->plan->career_id,
        'study_plan_id' => $test->plan->id,
        'semester' => 1,
        'code' => 'G'.Str::upper(Str::random(6)),
        'capacity' => 35,
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Inserta una sesión del grupo (el trigger copia lo del grupo) y devuelve su id.
 */
function schedulingSessionBeforeModality(string $groupId, int $day): string
{
    $id = (string) Str::uuid7();

    DB::table('group_sessions')->insert([
        'id' => $id,
        'group_id' => $groupId,
        'day' => $day,
        'starts_at' => '07:00',
        'ends_at' => '09:00',
        'kind' => 'teoria',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function schedulingConflictBeforeModality(object $test, Campus $campus, string $groupId): string
{
    $id = (string) Str::uuid7();

    DB::table('schedule_conflicts')->insert([
        'id' => $id,
        'term_id' => $test->term->id,
        'campus_id' => $campus->id,
        'department_id' => $test->subject->department_id,
        'career_id' => $test->plan->career_id,
        'group_id' => $groupId,
        'rule' => 'HC-02',
        'severity' => 'hard',
        'subject_type' => 'group',
        'subject_id' => $groupId,
        'related' => '{}',
        'message' => 'El salón ya está ocupado a esa hora.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * La clave de la modalidad del catálogo de cada fila, por llave.
 *
 * @param  array<string, string>  $ids
 * @return array<string, ?string>
 */
function schedulingModalityCodes(string $table, array $ids): array
{
    return array_map(
        fn (string $id): ?string => DB::table($table)->join('modalities', 'modalities.id', '=', "{$table}.modality_id")->where("{$table}.id", $id)->value('modalities.code'),
        $ids,
    );
}

function schedulingModalityId(string $code): string
{
    return Modality::query()->where('code', $code)->value('id');
}

it('llena la modalidad de grupos, sesiones y choques con la de su campus: C1 y C2 quedan en PRES', function () {
    $groups = [
        'C1' => schedulingGroupBeforeModality($this, $this->c1),
        'C2' => schedulingGroupBeforeModality($this, $this->c2),
        'EAD' => schedulingGroupBeforeModality($this, $this->ead),
    ];
    $sessions = ['C1' => schedulingSessionBeforeModality($groups['C1'], 1), 'C2' => schedulingSessionBeforeModality($groups['C2'], 2), 'EAD' => schedulingSessionBeforeModality($groups['EAD'], 3)];
    $conflicts = ['C1' => schedulingConflictBeforeModality($this, $this->c1, $groups['C1']), 'C2' => schedulingConflictBeforeModality($this, $this->c2, $groups['C2'])];

    $this->migration->up();

    expect(schedulingModalityCodes('groups', $groups))->toBe(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD'])
        ->and(schedulingModalityCodes('group_sessions', $sessions))->toBe(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD'])
        ->and(schedulingModalityCodes('schedule_conflicts', $conflicts))->toBe(['C1' => 'PRES', 'C2' => 'PRES']);

    foreach (['groups', 'group_sessions', 'schedule_conflicts'] as $table) {
        expect(DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', 'modality_id')->value('is_nullable'))->toBe('NO');
    }
});

it('una sesión nueva copia la modalidad de su grupo, aunque se escriba otra', function () {
    $this->migration->up();
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->ead->id, 'modality_id' => schedulingModalityId('EAD')]);

    $session = GroupSession::factory()->for($group)->create();
    $forced = (string) Str::uuid7();
    DB::table('group_sessions')->insert([
        'id' => $forced, 'group_id' => $group->id, 'day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'teoria',
        'modality_id' => schedulingModalityId('PRES'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($group->modality_id)->toBe(schedulingModalityId('EAD'))
        ->and($session->fresh()->modality_id)->toBe($group->modality_id)
        ->and(DB::table('group_sessions')->where('id', $forced)->value('modality_id'))->toBe($group->modality_id);
});

it('cambiar la modalidad del grupo la propaga a sus sesiones', function () {
    $this->migration->up();
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->c1->id]);
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->c1->id]);
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($group)->on(2, '07:00', '09:00')->create();
    $untouched = GroupSession::factory()->for($other)->on(3, '07:00', '09:00')->create();

    $group->update(['modality_id' => schedulingModalityId('EAD')]);

    expect($group->sessions()->pluck('modality_id')->unique()->all())->toBe([schedulingModalityId('EAD')])
        ->and($untouched->fresh()->modality_id)->toBe(schedulingModalityId('PRES'));
});

it('se detiene y nombra el campus si un grupo o un choque es de un campus sin modalidad del catálogo, y no deja nada', function () {
    $orphan = Campus::factory()->create(['code' => 'C9', 'modality_id' => null]);
    schedulingGroupBeforeModality($this, $this->c1);
    $group = schedulingGroupBeforeModality($this, $orphan);
    schedulingSessionBeforeModality($group, 1);
    schedulingConflictBeforeModality($this, $orphan, $group);

    expect(fn () => $this->migration->up())->toThrow(function (RuntimeException $exception) use ($orphan) {
        expect($exception->getMessage())->toContain('C9', $orphan->id, 'groups', 'schedule_conflicts')
            ->not->toContain('C1');
    });

    expect(Schema::hasColumn('groups', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('group_sessions', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('schedule_conflicts', 'modality_id'))->toBeFalse();
});
