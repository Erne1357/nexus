<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Models\Campus;

it('careerOptions da las carreras activas que se ofrecen en el campus, por código', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c1 = Campus::factory()->create(['code' => 'C1']);

    $offer = function (Career $career, Campus $campus, bool $active = true) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id, 'is_active' => $active]);
    };

    $isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $ige = Career::factory()->create(['code' => 'IGE', 'name' => 'Ingeniería en Gestión Empresarial']);
    $notOffered = Career::factory()->create(['code' => 'IND']);
    $inactiveCareer = Career::factory()->inactive()->create(['code' => 'ARQ']);
    $deleted = Career::factory()->create(['code' => 'CIV']);
    $otherCampus = Career::factory()->create(['code' => 'MEC']);

    $offer($isc, $c2);
    $offer($ige, $c2);
    $offer($notOffered, $c2, active: false);
    $offer($inactiveCareer, $c2);
    $offer($deleted, $c2);
    $deleted->delete();
    $offer($otherCampus, $c1);

    $options = app(ScopeLabels::class)->careerOptions($c2->id);

    expect($options)->toBe([
        $ige->id => 'IGE · Ingeniería en Gestión Empresarial',
        $isc->id => 'ISC · Ingeniería en Sistemas Computacionales',
    ])->and(array_keys($options))->toBe([$ige->id, $isc->id]);
});
