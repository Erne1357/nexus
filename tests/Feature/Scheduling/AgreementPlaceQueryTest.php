<?php

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\AgreementPlaceQuery;

/*
| AgreementPlaceQuery: el lugar de un acuerdo es el campus del salón en un préstamo y el del grupo en un grupo
| compartido, que no guarda campus (MS6, M12). La misma regla en SQL (filtro y cifras de la bandeja), leída de la base
| (avisos) y con el grupo ya cargado (filas).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->group = Group::factory()->create(['campus_id' => $this->c2->id]);
    $this->loan = InterdepartmentalAgreement::factory()->create(['term_id' => $this->group->term_id, 'campus_id' => $this->c1->id, 'group_id' => $this->group->id]);
    $this->share = InterdepartmentalAgreement::factory()->sharedGroup()->create(['term_id' => $this->group->term_id, 'group_id' => $this->group->id]);
    $this->place = app(AgreementPlaceQuery::class);
});

it('MS6: el préstamo está en el campus del salón y el grupo compartido en el del grupo, en sus tres formas', function () {
    $inCampus = fn (Campus $campus): array => $this->place->in(InterdepartmentalAgreement::query(), $campus)->pluck('id')->all();
    $group = $this->group->load('campus');

    expect($this->place->campusIdOf($this->loan))->toBe($this->c1->id)
        ->and($this->place->campusIdOf($this->share))->toBe($this->c2->id)
        ->and($inCampus($this->c1))->toBe([$this->loan->id])
        ->and($inCampus($this->c2))->toBe([$this->share->id])
        ->and($this->place->campusOf($this->loan->load('campus'), $group)?->id)->toBe($this->c1->id)
        ->and($this->place->campusOf($this->share->load('campus'), $group)?->id)->toBe($this->c2->id);
});
