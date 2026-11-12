<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Ampliar (ronda 2 de la carga por modalidad, ADR-0025): `academic_loads`, `career_proposals` e
| `interdepartmental_agreements` reciben `modality_id`, obligatoria y llena con la modalidad del catálogo de su campus
| (el puente `campuses.modality_id`); `proposal_items` recibe `campus_id`, opcional y llena con el campus de su
| propuesta. Ningún lector cambia todavía. Si una fila es de un campus sin modalidad del catálogo, se detiene, nombra el
| campus y no deja nada. Se prueba sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210401_add_modality_to_load_tables.php');
    $this->migration->down();
    $this->term = Term::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->ead = Campus::factory()->inactive()->create(['code' => 'EAD', 'modality' => CampusModality::Mixta]);
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->career = Career::factory()->create(['department_id' => $this->sis->id]);
    $this->user = User::factory()->create();
});

/**
 * Inserta una fila con la forma de antes de la ampliación (sin `modality_id`) y devuelve su id.
 *
 * @param  array<string, mixed>  $attributes
 */
function academicLoadRowBeforeModality(string $table, array $attributes): string
{
    $id = (string) Str::uuid7();

    DB::table($table)->insert(['id' => $id, ...$attributes, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * La clave de la modalidad del catálogo de una fila.
 */
function academicLoadModalityCode(string $table, string $id): ?string
{
    return DB::table($table)->join('modalities', 'modalities.id', '=', "{$table}.modality_id")->where("{$table}.id", $id)->value('modalities.code');
}

function academicLoadColumnIsNullable(string $table, string $column): bool
{
    return DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', $column)->value('is_nullable') === 'YES';
}

function academicLoadBeforeModality(object $test, Campus $campus, Department $department): string
{
    return academicLoadRowBeforeModality('academic_loads', ['term_id' => $test->term->id, 'campus_id' => $campus->id, 'department_id' => $department->id, 'status' => 'draft']);
}

function careerProposalBeforeModality(object $test, Campus $campus): string
{
    return academicLoadRowBeforeModality('career_proposals', ['term_id' => $test->term->id, 'campus_id' => $campus->id, 'career_id' => $test->career->id, 'status' => 'draft']);
}

function sharedGroupAgreementBeforeModality(object $test, Campus $campus): string
{
    return academicLoadRowBeforeModality('interdepartmental_agreements', [
        'term_id' => $test->term->id,
        'campus_id' => $campus->id,
        'kind' => 'shared_group',
        'from_department_id' => $test->cb->id,
        'to_department_id' => $test->sis->id,
        'group_id' => (string) Str::uuid7(),
        'career_id' => $test->career->id,
        'max_seats' => 5,
        'status' => 'proposed',
        'requested_by' => $test->user->id,
    ]);
}

it('llena la modalidad de cargas, propuestas y acuerdos con la de su campus: C1 y C2 quedan en PRES', function () {
    $loads = [
        'C1' => academicLoadBeforeModality($this, $this->c1, $this->sis),
        'C2' => academicLoadBeforeModality($this, $this->c2, $this->sis),
        'EAD' => academicLoadBeforeModality($this, $this->ead, $this->cb),
    ];
    $proposals = ['C1' => careerProposalBeforeModality($this, $this->c1), 'C2' => careerProposalBeforeModality($this, $this->c2)];
    $agreements = ['C1' => sharedGroupAgreementBeforeModality($this, $this->c1), 'C2' => sharedGroupAgreementBeforeModality($this, $this->c2)];

    $this->migration->up();

    expect(array_map(fn (string $id): ?string => academicLoadModalityCode('academic_loads', $id), $loads))->toBe(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD'])
        ->and(array_map(fn (string $id): ?string => academicLoadModalityCode('career_proposals', $id), $proposals))->toBe(['C1' => 'PRES', 'C2' => 'PRES'])
        ->and(array_map(fn (string $id): ?string => academicLoadModalityCode('interdepartmental_agreements', $id), $agreements))->toBe(['C1' => 'PRES', 'C2' => 'PRES']);

    foreach (['academic_loads', 'career_proposals', 'interdepartmental_agreements'] as $table) {
        expect(academicLoadColumnIsNullable($table, 'modality_id'))->toBeFalse()
            ->and(academicLoadColumnIsNullable($table, 'campus_id'))->toBeFalse();
    }
});

it('cada fila de la propuesta recibe el campus de su propuesta, y la columna queda opcional', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $item = fn (string $proposalId): string => academicLoadRowBeforeModality('proposal_items', [
        'career_proposal_id' => $proposalId,
        'subject_id' => Subject::factory()->create()->id,
        'study_plan_id' => $plan->id,
        'semester' => 1,
    ]);
    $inC1 = $item(careerProposalBeforeModality($this, $this->c1));
    $inC2 = $item(careerProposalBeforeModality($this, $this->c2));

    $this->migration->up();

    expect(DB::table('proposal_items')->where('id', $inC1)->value('campus_id'))->toBe($this->c1->id)
        ->and(DB::table('proposal_items')->where('id', $inC2)->value('campus_id'))->toBe($this->c2->id)
        ->and(academicLoadColumnIsNullable('proposal_items', 'campus_id'))->toBeTrue();
});

it('se detiene y nombra el campus si una fila es de un campus sin modalidad del catálogo, y no deja nada', function () {
    $orphan = Campus::factory()->create(['code' => 'C9', 'modality_id' => null]);
    academicLoadBeforeModality($this, $this->c1, $this->sis);
    academicLoadBeforeModality($this, $orphan, $this->sis);
    sharedGroupAgreementBeforeModality($this, $orphan);

    expect(fn () => $this->migration->up())->toThrow(function (RuntimeException $exception) use ($orphan) {
        expect($exception->getMessage())->toContain('C9', $orphan->id, 'academic_loads', 'interdepartmental_agreements')
            ->not->toContain('C1');
    });

    expect(Schema::hasColumn('academic_loads', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('career_proposals', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('interdepartmental_agreements', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('proposal_items', 'campus_id'))->toBeFalse();
});
