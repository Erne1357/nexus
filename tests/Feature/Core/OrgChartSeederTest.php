<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;

it('siembra el organigrama tipo bajo la Dirección (AZ-S1), con Servicios Escolares en la Subdirección Académica (D20)', function () {
    $this->seed(OrgChartSeeder::class);

    $expected = [
        'SUB-PLANEACION' => [OrgUnitKind::Subdireccion, 'DIRECCION'],
        'SUB-ADMINISTRATIVA' => [OrgUnitKind::Subdireccion, 'DIRECCION'],
        'DIV-PROFESIONAL' => [OrgUnitKind::Division, 'SUB-ACADEMICA'],
        'DIV-POSGRADO' => [OrgUnitKind::Division, 'SUB-ACADEMICA'],
        'DESARROLLO-ACADEMICO' => [OrgUnitKind::Departamento, 'SUB-ACADEMICA'],
        'EDUCACION-DISTANCIA' => [OrgUnitKind::Departamento, 'SUB-ACADEMICA'],
        'SERVICIOS-ESCOLARES' => [OrgUnitKind::Departamento, 'SUB-ACADEMICA'],
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

it('si el admin borró una rama, sembrar no crea sus hijos', function () {
    $branch = OrgUnit::factory()->create([
        'parent_id' => OrgUnit::where('code', 'DIRECCION')->value('id'),
        'code' => 'SUB-PLANEACION',
        'kind' => OrgUnitKind::Subdireccion,
    ]);
    $branch->delete();

    $this->seed(OrgChartSeeder::class);

    expect(OrgUnit::whereIn('code', ['VINCULACION', 'EXTRAESCOLARES'])->exists())->toBeFalse()
        ->and(OrgUnit::withTrashed()->where('code', 'SUB-PLANEACION')->sole()->trashed())->toBeTrue()
        ->and(OrgUnit::where('code', 'DIV-PROFESIONAL')->exists())->toBeTrue();
});

it('D20: en una instalación que ya tiene Servicios Escolares en otra subdirección no la mueve; eso es del Organigrama', function () {
    $planning = OrgUnit::factory()->create(['parent_id' => OrgUnit::where('code', 'DIRECCION')->value('id'), 'code' => 'SUB-PLANEACION', 'kind' => OrgUnitKind::Subdireccion]);
    OrgUnit::factory()->create(['parent_id' => $planning->id, 'code' => 'SERVICIOS-ESCOLARES', 'kind' => OrgUnitKind::Departamento]);

    $this->seed(OrgChartSeeder::class);

    expect(OrgUnit::where('code', 'SERVICIOS-ESCOLARES')->sole()->parent->code)->toBe('SUB-PLANEACION');
});

it('el sembrador ignora un departamento académico con la clave de una unidad estructural', function () {
    Department::factory()->create(['code' => 'VINCULACION']);

    $this->seed(OrgChartSeeder::class);

    expect(OrgUnit::where('code', 'VINCULACION')->where('kind', OrgUnitKind::Departamento)->exists())->toBeTrue();
});
