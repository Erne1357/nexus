<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Queries\BuildingQuery;

/*
| BuildingQuery::groupedByActiveCampus(): los edificios de los campus activos, agrupados por campus, para el select con
| grupos que mueve un salón a otro campus al validar los catálogos (CV-S10).
*/

it('CV-S10: agrupa los edificios sin borrar de los campus activos por campus y clave, y dice los inactivos', function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $closed = Campus::factory()->create(['code' => 'C3', 'is_active' => false]);
    $y = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'Y', 'name' => 'Edificio Y']);
    $lc = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio', 'is_active' => false]);
    $e2 = Building::factory()->create(['campus_id' => $c2->id, 'code' => 'E2', 'name' => 'Edificio 2']);
    Building::factory()->create(['campus_id' => $c2->id])->delete();
    Building::factory()->create(['campus_id' => $closed->id]);

    expect(app(BuildingQuery::class)->groupedByActiveCampus())->toBe([
        ['id' => $c1->id, 'code' => 'C1', 'name' => 'Campus I', 'buildings' => [['id' => $lc->id, 'name' => 'LC · Laboratorio (inactivo)'], ['id' => $y->id, 'name' => 'Y · Edificio Y']]],
        ['id' => $c2->id, 'code' => 'C2', 'name' => 'Campus II', 'buildings' => [['id' => $e2->id, 'name' => 'E2 · Edificio 2']]],
    ]);
});
