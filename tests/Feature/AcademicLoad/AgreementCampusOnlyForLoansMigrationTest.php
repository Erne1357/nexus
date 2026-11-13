<?php

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Contraer (ronda 2 de la carga por modalidad, ADR-0025): `interdepartmental_agreements.campus_id` queda solo en el
| préstamo de salón, donde es el campus del salón (M2: el campus es el lugar). El acuerdo de un grupo compartido es de
| la modalidad y su campus es el del grupo, así que la columna queda nula y el CHECK de forma lo exige; el préstamo la
| sigue exigiendo (sin ella la llave compuesta al salón no se revisaría). Se quitan los índices de alcance por campus:
| los de modalidad los reemplazan desde 2026_11_12_210401. Se prueba sobre la forma vieja: `down()`, filas y `up()`.
*/

const AGREEMENTS_CONTRACTION = 'app/Modules/AcademicLoad/Database/Migrations/2026_11_12_211103_keep_agreement_campus_for_room_loans.php';

beforeEach(function () {
    $this->migration = require base_path(AGREEMENTS_CONTRACTION);
    $this->migration->down();
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->group = Group::factory()->create(['campus_id' => $this->c2->id]);
    $this->shared = InterdepartmentalAgreement::factory()->sharedGroup()->create(['campus_id' => $this->c2->id, 'group_id' => $this->group->id]);
    $this->loan = InterdepartmentalAgreement::factory()->create(['campus_id' => $this->c2->id]);
});

it('deja sin campus el acuerdo de grupo compartido y el préstamo de salón conserva el suyo', function () {
    $this->migration->up();

    expect($this->shared->fresh()->campus_id)->toBeNull()
        ->and($this->loan->fresh()->campus_id)->toBe($this->c2->id);
});

it('el CHECK de forma exige el campus en el préstamo y lo prohíbe en el grupo compartido', function () {
    $this->migration->up();

    expect(fn () => DB::transaction(fn () => InterdepartmentalAgreement::factory()->sharedGroup()->create(['campus_id' => $this->c2->id])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_shape_check')
        ->and(fn () => DB::transaction(fn () => DB::table('interdepartmental_agreements')->where('id', $this->loan->id)->update(['campus_id' => null])))
        ->toThrow(QueryException::class, 'interdepartmental_agreements_shape_check');
});

it('quita los índices de alcance por campus', function () {
    $this->migration->up();

    $indexes = collect(DB::select("select indexname from pg_indexes where tablename = 'interdepartmental_agreements'"))->pluck('indexname');

    expect($indexes->filter(fn (string $name): bool => str_contains($name, 'campus_id'))->values()->all())->toBe([]);
});

it('down() devuelve a cada grupo compartido el campus de su grupo y se niega si el grupo no tiene campus', function () {
    $this->migration->up();
    $this->migration->down();

    expect($this->shared->fresh()->campus_id)->toBe($this->c2->id);

    $this->migration->up();
    $orphan = InterdepartmentalAgreement::factory()->sharedGroup()->create(['campus_id' => null]);

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, $orphan->id)
        ->and(InterdepartmentalAgreement::query()->where('kind', AgreementKind::SharedGroup)->whereNotNull('campus_id')->exists())->toBeFalse();
});
