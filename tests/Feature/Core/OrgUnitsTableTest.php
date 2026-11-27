<?php

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Árbol del organigrama (ADR-0018, AZ-S1): solo se prueba la tabla y lo que siembra la migración.
*/

it('deja la Dirección como raíz y Subdirección Académica como su hija', function () {
    $root = OrgUnit::query()->where('code', 'DIRECCION')->sole();

    expect($root->parent_id)->toBeNull()
        ->and($root->kind)->toBe(OrgUnitKind::Direccion)
        ->and(OrgUnit::query()->where('code', 'SUB-ACADEMICA')->sole()->parent_id)->toBe($root->id);
});

it('rechaza una segunda raíz', function () {
    expect(fn () => OrgUnit::factory()->create(['parent_id' => null]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('no repite la clave de una unidad, tampoco contra borradas', function () {
    $unit = OrgUnit::factory()->create(['code' => 'X-1']);
    $unit->delete();

    expect(fn () => OrgUnit::factory()->create(['code' => 'X-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('admite la misma clave en dos departamentos académicos', function () {
    OrgUnit::factory()->count(2)->create(['code' => 'ISC', 'kind' => OrgUnitKind::DepartamentoAcademico]);

    expect(OrgUnit::query()->where('code', 'ISC')->count())->toBe(2);
});

it('rechaza un tipo desconocido', function () {
    expect(fn () => DB::table('org_units')->insert([
        'code' => 'Z-1',
        'name' => 'Rara',
        'kind' => 'otro',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'org_units_kind_check');
});

it('la base rechaza que una unidad sea su propia madre o cuelgue de su descendiente', function () {
    $a = OrgUnit::factory()->create();
    $b = OrgUnit::factory()->create(['parent_id' => $a->id]);
    $c = OrgUnit::factory()->create(['parent_id' => $b->id]);

    expect(fn () => DB::transaction(fn () => $a->update(['parent_id' => $a->id])))
        ->toThrow(QueryException::class, 'ciclo')
        ->and(fn () => DB::transaction(fn () => $a->update(['parent_id' => $c->id])))
        ->toThrow(QueryException::class, 'ciclo');

    $c->update(['parent_id' => $a->id]);

    expect($c->fresh()->parent_id)->toBe($a->id);
});
