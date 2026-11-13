<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Contraer la propuesta a una por carrera y modalidad (M6, confirmado el 12 de noviembre de 2026; MS12, supuesto por
| confirmar): por periodo, modalidad y carrera, las propuestas de C1 y C2 se funden en una y sus filas conservan su
| campus. Tiene historia la propuesta que ya no está en borrador, la enviada (`submitted_at`) o la que tiene alguna
| parte decidida (aceptada o regresada). Se queda la que tiene historia o, si ninguna, la más antigua; la otra se borra
| con su entrada en la bitácora, y sus filas, sus alumnos posibles (también los de cada campus) y sus partes sin
| decidir pasan a la que se queda. Se detiene, con la carrera, el periodo y los ids, y no cambia nada, si las dos tienen
| historia (HC-18) o si la que tiene historia absorbería otra que pide grupos (MS12). Después la llave única es
| (periodo, modalidad, carrera), `career_proposals.campus_id` se quita y las filas son únicas por (propuesta, materia,
| campus), también sin campus. `down()` se niega con una propuesta de filas de varios campus o sin campus, y con una que
| queda sin campus. Se prueba sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210601_make_career_proposals_per_modality.php');
    $this->migration->down();
    $this->term = Term::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->presencial = Modality::query()->where('code', 'PRES')->sole();
    $this->career = Career::factory()->create(['code' => 'ISC']);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $this->calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    $this->user = User::factory()->create();
});

/**
 * Inserta una propuesta con la forma de antes (con campus) y una fila de Cálculo en su campus; devuelve su id.
 *
 * @param  array<string, mixed>  $attributes
 */
function proposalBeforePerModality(object $test, Campus $campus, string $createdAt, array $attributes = []): string
{
    $id = (string) Str::uuid7();

    DB::table('career_proposals')->insert([
        'id' => $id,
        'term_id' => $test->term->id,
        'campus_id' => $campus->id,
        'modality_id' => $test->presencial->id,
        'career_id' => $test->career->id,
        'status' => 'draft',
        'eligible_students' => 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        ...$attributes,
    ]);

    DB::table('proposal_items')->insert([
        'career_proposal_id' => $id,
        'campus_id' => $campus->id,
        'subject_id' => $test->calculo->id,
        'study_plan_id' => $test->plan->id,
        'semester' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function partBeforePerModality(string $proposalId, Department $department, string $status = 'pending'): string
{
    $id = (string) Str::uuid7();

    DB::table('proposal_reviews')->insert([
        'id' => $id,
        'career_proposal_id' => $proposalId,
        'department_id' => $department->id,
        'status' => $status,
        'comment' => $status === 'returned' ? 'Ajusta los grupos.' : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('M6: funde en una las propuestas en borrador de C1 y C2 de la misma carrera, periodo y modalidad, y cada fila conserva su campus', function () {
    $older = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00', ['eligible_students' => 40]);
    $newer = proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00', ['eligible_students' => 150]);

    $this->migration->up();

    expect(DB::table('career_proposals')->pluck('id')->all())->toBe([$older])
        ->and(DB::table('career_proposals')->where('id', $older)->value('eligible_students'))->toBe(190)
        ->and(DB::table('proposal_items')->where('career_proposal_id', $older)->orderBy('campus_id')->pluck('campus_id')->all())
        ->toBe(collect([$this->c1->id, $this->c2->id])->sort()->values()->all())
        ->and(Schema::hasColumn('career_proposals', 'campus_id'))->toBeFalse()
        ->and(DB::table('activity_log')->where('subject_id', $newer)->where('event', 'deleted')->value('properties'))->toContain($older);
});

it('MS12: se queda con la propuesta que tiene historia aunque la otra sea más antigua', function (string $history) {
    proposalBeforePerModality($this, $this->c1, '2026-11-01 10:00:00');
    $withHistory = proposalBeforePerModality($this, $this->c2, '2026-11-02 10:00:00');
    match ($history) {
        'submitted' => DB::table('career_proposals')->where('id', $withHistory)->update(['status' => 'submitted', 'submitted_by' => $this->user->id, 'submitted_at' => now()]),
        default => partBeforePerModality($withHistory, Department::factory()->create(), $history),
    };

    $this->migration->up();

    expect(DB::table('career_proposals')->pluck('id')->all())->toBe([$withHistory])
        ->and(DB::table('proposal_items')->where('career_proposal_id', $withHistory)->count())->toBe(2);
})->with([
    'enviada' => ['submitted'],
    'con una parte aceptada' => ['accepted'],
    'con una parte regresada' => ['returned'],
]);

it('MS12: las partes sin decidir de la que se borra pasan a la que se queda, o se juntan con la que ya tiene', function () {
    $cb = Department::factory()->create(['code' => 'CB']);
    $sc = Department::factory()->create(['code' => 'SC']);
    $kept = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00');
    $absorbed = proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00');
    $keptPart = partBeforePerModality($kept, $cb);
    partBeforePerModality($absorbed, $cb);
    $moved = partBeforePerModality($absorbed, $sc);

    $this->migration->up();

    expect(DB::table('proposal_reviews')->orderBy('department_id')->get(['id', 'career_proposal_id'])->map(fn (object $row): array => (array) $row)->sortBy('id')->values()->all())
        ->toBe(collect([['id' => $keptPart, 'career_proposal_id' => $kept], ['id' => $moved, 'career_proposal_id' => $kept]])->sortBy('id')->values()->all());
});

it('MS12, HC-18: se detiene con los ids si las dos propuestas tienen historia y no cambia nada', function () {
    $c1 = proposalBeforePerModality($this, $this->c1, '2026-11-01 10:00:00', ['status' => 'submitted', 'submitted_at' => now()]);
    $c2 = proposalBeforePerModality($this, $this->c2, '2026-11-02 10:00:00');
    partBeforePerModality($c2, Department::factory()->create(), 'accepted');

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c1)
        ->and(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c2);

    expect(DB::table('career_proposals')->count())->toBe(2)
        ->and(DB::table('proposal_items')->count())->toBe(2)
        ->and(Schema::hasColumn('career_proposals', 'campus_id'))->toBeTrue();
});

it('no funde propuestas de carreras o periodos distintos, y después rechaza otra propuesta de la carrera en la modalidad y una fila repetida, también sin campus', function () {
    $other = Career::factory()->create(['code' => 'IGE']);
    $isc = proposalBeforePerModality($this, $this->c1, '2026-11-01 10:00:00');
    $ige = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00', ['career_id' => $other->id]);

    $this->migration->up();

    $row = ['career_proposal_id' => $isc, 'subject_id' => $this->calculo->id, 'study_plan_id' => $this->plan->id, 'semester' => 1, 'created_at' => now(), 'updated_at' => now()];
    DB::table('proposal_items')->insert([...$row, 'campus_id' => null]);

    expect(DB::table('career_proposals')->pluck('id')->sort()->values()->all())->toBe(collect([$isc, $ige])->sort()->values()->all())
        ->and(fn () => DB::transaction(fn () => DB::table('career_proposals')->insert(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'career_id' => $this->career->id, 'status' => 'draft'])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('proposal_items')->insert([...$row, 'campus_id' => $this->c1->id])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('proposal_items')->insert([...$row, 'campus_id' => null])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('agrega los índices de los alumnos por carrera, modalidad y campus y de las filas por campus', function () {
    $this->migration->up();

    $indexes = fn (string $table): string => DB::table('pg_indexes')->where('tablename', $table)->pluck('indexdef')->implode("\n");

    expect($indexes('students'))->toContain('USING btree (career_id, modality_id, campus_id)')
        ->and($indexes('proposal_items'))->toContain('USING btree (campus_id)');
});

it('MS12: se detiene si la propuesta con historia absorbería otra que pide grupos, y no cambia nada', function () {
    $withHistory = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00', ['status' => 'submitted', 'submitted_by' => $this->user->id, 'submitted_at' => now()]);
    $draft = proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00');
    DB::table('proposal_items')->where('career_proposal_id', $draft)->update(['requested_groups' => 2]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, $draft)
        ->and(fn () => $this->migration->up())->toThrow(RuntimeException::class, $withHistory)
        ->and(fn () => $this->migration->up())->toThrow(RuntimeException::class, $this->career->id);

    expect(DB::table('career_proposals')->count())->toBe(2)
        ->and(DB::table('proposal_items')->where('career_proposal_id', $draft)->count())->toBe(1)
        ->and(Schema::hasColumn('career_proposals', 'campus_id'))->toBeTrue()
        ->and(Schema::hasColumn('career_proposals', 'eligible_students_by_campus'))->toBeFalse();
});

it('MS12: una propuesta que ya no está en borrador tiene historia aunque no tenga fecha de envío', function () {
    proposalBeforePerModality($this, $this->c1, '2026-11-01 10:00:00');
    $accepted = proposalBeforePerModality($this, $this->c2, '2026-11-02 10:00:00', ['status' => 'accepted']);

    $this->migration->up();

    expect(DB::table('career_proposals')->pluck('id')->all())->toBe([$accepted]);
});

it('M6: guarda los alumnos posibles de cada campus de las propuestas que funde', function () {
    $older = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00', ['eligible_students' => 40]);
    proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00', ['eligible_students' => 150]);
    $alone = proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00', ['eligible_students' => 9, 'career_id' => Career::factory()->create()->id]);

    $this->migration->up();

    $split = fn (string $id): array => json_decode(DB::table('career_proposals')->where('id', $id)->value('eligible_students_by_campus'), true);

    expect($split($older))->toEqualCanonicalizing([$this->c1->id => 150, $this->c2->id => 40])
        ->and($split($alone))->toBe([$this->c1->id => 9]);
});

it('down() se niega con una propuesta de filas de varios campus o sin campus, y regresa y vuelve a subir con datos', function () {
    $merged = proposalBeforePerModality($this, $this->c2, '2026-11-01 10:00:00');
    proposalBeforePerModality($this, $this->c1, '2026-11-02 10:00:00');
    $this->migration->up();

    $refusal = 'Estas propuestas tienen filas de varios campus o sin campus y no se pueden regresar a una por campus: ';

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, "{$refusal}{$merged}.");

    DB::table('proposal_items')->where('campus_id', $this->c1->id)->delete();
    DB::table('proposal_items')->where('career_proposal_id', $merged)->update(['campus_id' => null]);

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, "{$refusal}{$merged}.");

    DB::table('proposal_items')->where('career_proposal_id', $merged)->update(['campus_id' => $this->c2->id]);
    $this->migration->down();

    expect(DB::table('career_proposals')->where('id', $merged)->value('campus_id'))->toBe($this->c2->id)
        ->and(Schema::hasColumn('career_proposals', 'eligible_students_by_campus'))->toBeFalse();

    $this->migration->up();

    expect(DB::table('career_proposals')->pluck('id')->all())->toBe([$merged])
        ->and(Schema::hasColumn('career_proposals', 'campus_id'))->toBeFalse();
});

it('down() se niega con una propuesta sin filas de una modalidad sin campus, y no cambia nada', function () {
    $this->migration->up();
    $withoutCampuses = Modality::factory()->create(['code' => 'M902']);
    $orphan = (string) Str::uuid7();
    DB::table('career_proposals')->insert(['id' => $orphan, 'term_id' => $this->term->id, 'modality_id' => $withoutCampuses->id, 'career_id' => $this->career->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, "Estas propuestas son de una modalidad sin campus y no se pueden regresar a una por campus: {$orphan}.");

    expect(Schema::hasColumn('career_proposals', 'campus_id'))->toBeFalse()
        ->and(Schema::hasColumn('career_proposals', 'eligible_students_by_campus'))->toBeTrue();
});
