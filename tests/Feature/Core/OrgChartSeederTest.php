<?php

use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;

it('siembra el organigrama tipo bajo la Dirección (AZ-S1)', function () {
    $this->seed(OrgChartSeeder::class);

    $expected = [
        'SUB-PLANEACION' => [OrgUnitKind::Subdireccion, 'DIRECCION'],
        'SUB-ADMINISTRATIVA' => [OrgUnitKind::Subdireccion, 'DIRECCION'],
        'DIV-PROFESIONAL' => [OrgUnitKind::Division, 'SUB-ACADEMICA'],
        'DIV-POSGRADO' => [OrgUnitKind::Division, 'SUB-ACADEMICA'],
        'DESARROLLO-ACADEMICO' => [OrgUnitKind::Departamento, 'SUB-ACADEMICA'],
        'EDUCACION-DISTANCIA' => [OrgUnitKind::Departamento, 'SUB-ACADEMICA'],
        'SERVICIOS-ESCOLARES' => [OrgUnitKind::Departamento, 'SUB-PLANEACION'],
        'VINCULACION' => [OrgUnitKind::Departamento, 'SUB-PLANEACION'],
        'EXTRAESCOLARES' => [OrgUnitKind::Departamento, 'SUB-PLANEACION'],
    ];

    foreach ($expected as $code => [$kind, $parentCode]) {
        $unit = OrgUnit::where('code', $code)->firstOrFail();

        expect($unit->kind)->toBe($kind)
            ->and($unit->parent->code)->toBe($parentCode)
            ->and($unit->is_active)->toBeTrue();
    }
});

it('correrlo dos veces no duplica ni mueve lo que el admin reubicó', function () {
    $this->seed(OrgChartSeeder::class);
    $count = OrgUnit::withTrashed()->count();

    OrgUnit::where('code', 'VINCULACION')->update(['parent_id' => OrgUnit::where('code', 'DIRECCION')->value('id')]);
    OrgUnit::where('code', 'EXTRAESCOLARES')->firstOrFail()->delete();

    $this->seed(OrgChartSeeder::class);

    expect(OrgUnit::withTrashed()->count())->toBe($count)
        ->and(OrgUnit::where('code', 'VINCULACION')->first()->parent->code)->toBe('DIRECCION')
        ->and(OrgUnit::where('code', 'EXTRAESCOLARES')->exists())->toBeFalse();
});
