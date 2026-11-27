<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| La migración que pasa la apertura del periodo y su calendario de campus a modalidad (M4, confirmado el 12 de
| noviembre de 2026: Presencial se abre una vez para C1 y C2, con un solo calendario). Las aperturas de los campus de
| una misma modalidad (por el puente `campuses.modality_id`) se funden en una, la más antigua, que conserva quién y
| cuándo; las ventanas propias de un campus pasan a su modalidad, y si dos campus de la misma modalidad tienen la misma
| etapa con fechas distintas, o si un campus tiene ventana propia en una etapa y otro de la misma modalidad usa la general,
| se detiene, las lista y no deja nada. Quita `campus_id` de las dos tablas. Se prueba
| sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
    $this->migration = require base_path('app/Modules/Core/Database/Migrations/2026_11_12_210301_open_terms_per_modality.php');
    $this->migration->down();

    $this->c1 = campusBeforeContraction('C1');
    $this->c2 = campusBeforeContraction('C2');
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->presencial = modalityOf('PRES');
});

/**
 * Una apertura con la forma de antes: por periodo y campus.
 */
function openingBeforeModality(string $termId, string $campusId, string $openedAt, ?string $openedBy = null): string
{
    $id = (string) Str::uuid7();

    DB::table('term_openings')->insert([
        'id' => $id,
        'term_id' => $termId,
        'campus_id' => $campusId,
        'opened_by' => $openedBy ?? User::factory()->create()->id,
        'opened_at' => $openedAt,
        'created_at' => $openedAt,
        'updated_at' => $openedAt,
    ]);

    return $id;
}

/**
 * Una ventana con la forma de antes: general (campus nulo) o propia de un campus.
 */
function stageBeforeModality(string $termId, ?string $campusId, string $stage, string $startsOn, string $endsOn): string
{
    $id = (string) Str::uuid7();

    DB::table('term_stages')->insert([
        'id' => $id,
        'term_id' => $termId,
        'campus_id' => $campusId,
        'stage' => $stage,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('M4: las aperturas de C1 y C2 del mismo periodo quedan en una de PRES, con quién y cuándo de la más antigua', function () {
    $first = User::factory()->create();
    openingBeforeModality($this->term->id, $this->c2->id, '2026-11-03 09:00:00', $first->id);
    openingBeforeModality($this->term->id, $this->c1->id, '2026-11-05 12:30:00');

    $this->migration->up();

    $opening = DB::table('term_openings')->sole();

    expect($opening->term_id)->toBe($this->term->id)
        ->and($opening->modality_id)->toBe($this->presencial->id)
        ->and($opening->opened_by)->toBe($first->id)
        ->and(CarbonImmutable::parse($opening->opened_at)->toDateTimeString())->toBe('2026-11-03 09:00:00')
        ->and(Schema::hasColumn('term_openings', 'campus_id'))->toBeFalse();
});

it('M4: cada periodo conserva su apertura, y una apertura de un solo campus pasa a su modalidad', function () {
    $other = Term::factory()->create(['code' => '20263']);
    openingBeforeModality($this->term->id, $this->c2->id, '2026-11-03 09:00:00');
    openingBeforeModality($other->id, $this->c1->id, '2026-06-01 09:00:00');

    $this->migration->up();

    expect(DB::table('term_openings')->orderBy('term_id')->get(['term_id', 'modality_id'])->map(fn ($row): array => (array) $row)->sortBy('term_id')->values()->all())
        ->toEqualCanonicalizing([
            ['term_id' => $this->term->id, 'modality_id' => $this->presencial->id],
            ['term_id' => $other->id, 'modality_id' => $this->presencial->id],
        ]);
});

it('M4: las ventanas propias de un campus pasan a su modalidad; la general sigue sin modalidad', function () {
    stageBeforeModality($this->term->id, null, 'proposal', '2026-11-02', '2026-11-13');
    stageBeforeModality($this->term->id, $this->c1->id, 'scheduling', '2026-11-23', '2026-12-11');
    DB::table('campuses')->where('id', $this->c2->id)->update(['deleted_at' => now()]);

    $this->migration->up();

    $stages = DB::table('term_stages')->get()->keyBy('stage');

    expect($stages)->toHaveCount(2)
        ->and($stages['proposal']->modality_id)->toBeNull()
        ->and($stages['scheduling']->modality_id)->toBe($this->presencial->id)
        ->and($stages['scheduling']->starts_on)->toBe('2026-11-23')
        ->and(Schema::hasColumn('term_stages', 'campus_id'))->toBeFalse();
});

it('M4: la misma etapa con las mismas fechas en C1 y C2 queda en una sola ventana de PRES', function () {
    stageBeforeModality($this->term->id, $this->c1->id, 'closing', '2026-12-07', '2026-12-16');
    stageBeforeModality($this->term->id, $this->c2->id, 'closing', '2026-12-07', '2026-12-16');

    $this->migration->up();

    $window = DB::table('term_stages')->sole();

    expect($window->modality_id)->toBe($this->presencial->id)
        ->and([$window->starts_on, $window->ends_on])->toBe(['2026-12-07', '2026-12-16']);
});

it('M4: la misma etapa con fechas distintas en C1 y C2 detiene la migración, lista los ids y no deja nada', function () {
    $c1 = stageBeforeModality($this->term->id, $this->c1->id, 'closing', '2026-12-07', '2026-12-16');
    $c2 = stageBeforeModality($this->term->id, $this->c2->id, 'closing', '2026-12-08', '2026-12-16');
    openingBeforeModality($this->term->id, $this->c2->id, '2026-11-03 09:00:00');
    openingBeforeModality($this->term->id, $this->c1->id, '2026-11-05 09:00:00');

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c1)
        ->and(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c2);

    expect(Schema::hasColumn('term_stages', 'campus_id'))->toBeTrue()
        ->and(Schema::hasColumn('term_stages', 'modality_id'))->toBeFalse()
        ->and(Schema::hasColumn('term_openings', 'campus_id'))->toBeTrue()
        ->and(DB::table('term_openings')->count())->toBe(2)
        ->and(DB::table('term_stages')->count())->toBe(2);
});

it('M4: una etapa con ventana propia en un campus y la general en otro campus de la misma modalidad detiene la migración, la lista y no deja nada', function () {
    $own = stageBeforeModality($this->term->id, $this->c1->id, 'scheduling', '2026-11-23', '2026-12-11');
    stageBeforeModality($this->term->id, null, 'scheduling', '2026-11-16', '2026-12-04');
    stageBeforeModality($this->term->id, $this->c1->id, 'closing', '2026-12-07', '2026-12-16');
    stageBeforeModality($this->term->id, $this->c2->id, 'closing', '2026-12-07', '2026-12-16');

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, "20271 PRES scheduling: {$own} (C1: 2026-11-23 a 2026-12-11); con la general: C2.");

    expect(Schema::hasColumn('term_stages', 'campus_id'))->toBeTrue()
        ->and(Schema::hasColumn('term_stages', 'modality_id'))->toBeFalse()
        ->and(DB::table('term_stages')->count())->toBe(4);
});

it('una apertura o ventana de un campus sin modalidad del catálogo detiene la migración y lo nombra', function () {
    $loose = campusBeforeContraction('C9');
    DB::table('campuses')->where('id', $loose->id)->update(['modality_id' => null]);
    openingBeforeModality($this->term->id, $loose->id, '2026-11-03 09:00:00');

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, "C9 ({$loose->id})");

    expect(Schema::hasColumn('term_openings', 'campus_id'))->toBeTrue();
});

it('M4: la base no admite dos aperturas del mismo periodo y modalidad, ni dos ventanas de la misma etapa y modalidad', function () {
    $this->migration->up();
    $opener = User::factory()->create()->id;
    $opening = fn () => DB::transaction(fn () => DB::table('term_openings')->insert([
        'term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'opened_by' => $opener, 'opened_at' => now(),
    ]));
    $general = fn () => DB::transaction(fn () => DB::table('term_stages')->insert([
        'term_id' => $this->term->id, 'modality_id' => null, 'stage' => 'proposal', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13',
    ]));

    $opening();
    $general();

    expect($opening)->toThrow(QueryException::class, 'term_openings_term_id_modality_id_unique')
        ->and($general)->toThrow(QueryException::class, 'term_stages_term_id_modality_id_stage_unique');
});

it('down() devuelve cada apertura y ventana de modalidad a cada campus de esa modalidad', function () {
    $this->migration->up();
    $opener = User::factory()->create()->id;
    DB::table('term_openings')->insert(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'opened_by' => $opener, 'opened_at' => now()]);
    DB::table('term_stages')->insert(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'stage' => 'closing', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-16']);
    DB::table('term_stages')->insert(['term_id' => $this->term->id, 'modality_id' => null, 'stage' => 'proposal', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);
    $lonely = Modality::factory()->create();
    DB::table('term_stages')->insert(['term_id' => $this->term->id, 'modality_id' => $lonely->id, 'stage' => 'rooms', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-04']);

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, $lonely->code);

    DB::table('term_stages')->where('modality_id', $lonely->id)->delete();
    $this->migration->down();

    expect(DB::table('term_openings')->pluck('campus_id')->sort()->values()->all())->toEqual(collect([$this->c1->id, $this->c2->id])->sort()->values()->all())
        ->and(DB::table('term_stages')->where('stage', 'closing')->pluck('campus_id')->sort()->values()->all())->toEqual(collect([$this->c1->id, $this->c2->id])->sort()->values()->all())
        ->and(DB::table('term_stages')->where('stage', 'proposal')->sole()->campus_id)->toBeNull()
        ->and(Schema::hasColumn('term_openings', 'modality_id'))->toBeFalse();
});
