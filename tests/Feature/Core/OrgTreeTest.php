<?php

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| OrgTree (ADR-0018, AZ-S1): quién cubre a quién en el organigrama, desde un mapa cacheado.
*/

/**
 * @return array{a: OrgUnit, a1: OrgUnit, a1a: OrgUnit, b: OrgUnit}
 */
function sampleOrgTree(): array
{
    $a = OrgUnit::factory()->create();
    $a1 = OrgUnit::factory()->create(['parent_id' => $a->id]);
    $a1a = OrgUnit::factory()->create(['parent_id' => $a1->id]);
    $b = OrgUnit::factory()->create();

    return compact('a', 'a1', 'a1a', 'b');
}

/**
 * @return list<string>
 */
function orgUnitQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'org_units'))->values()->all();
}

beforeEach(function () {
    Cache::flush();
    app()->forgetScopedInstances();
});

it('una unidad cubre a sí misma y a su subárbol, y no a sus ancestros ni a sus hermanas', function () {
    ['a' => $a, 'a1' => $a1, 'a1a' => $a1a, 'b' => $b] = sampleOrgTree();
    $tree = app(OrgTree::class);

    expect($tree->covers($a->id, $a1a->id))->toBeTrue()
        ->and($tree->covers($a->id, $a->id))->toBeTrue()
        ->and($tree->covers($a1->id, $a->id))->toBeFalse()
        ->and($tree->covers($a->id, $b->id))->toBeFalse()
        ->and($tree->descendantsOrSelf($a->id))->toEqualCanonicalizing([$a->id, $a1->id, $a1a->id])
        ->and($tree->descendantsOrSelf('no-existe'))->toBe([]);
});

it('mover una unidad cambia lo que cubre en la siguiente petición', function () {
    ['a' => $a, 'a1' => $a1, 'a1a' => $a1a, 'b' => $b] = sampleOrgTree();
    expect(app(OrgTree::class)->covers($a->id, $a1a->id))->toBeTrue();

    $this->travel(2)->seconds();
    $a1->update(['parent_id' => $b->id]);
    app()->forgetScopedInstances();

    $tree = app(OrgTree::class);
    expect($tree->covers($b->id, $a1a->id))->toBeTrue()
        ->and($tree->covers($a->id, $a1a->id))->toBeFalse();
});

it('conserva en el mapa a las unidades borradas porque la jerarquía es estructural', function () {
    ['a' => $a, 'a1' => $a1, 'a1a' => $a1a] = sampleOrgTree();
    $this->travel(2)->seconds();
    $a1->delete();
    app()->forgetScopedInstances();

    expect(app(OrgTree::class)->covers($a->id, $a1a->id))->toBeTrue();
});

it('consulta la base una vez por petición cuando el mapa ya está en caché', function () {
    ['a' => $a, 'a1a' => $a1a] = sampleOrgTree();
    app(OrgTree::class)->covers($a->id, $a1a->id);
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $tree = app(OrgTree::class);
    $tree->covers($a->id, $a1a->id);
    $tree->descendantsOrSelf($a->id);

    expect(orgUnitQueries())->toHaveCount(1);
});

it('con la caché fría hace a lo sumo dos consultas: huella y armado del mapa', function () {
    ['a' => $a, 'a1a' => $a1a] = sampleOrgTree();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $tree = app(OrgTree::class);
    $tree->covers($a->id, $a1a->id);
    $tree->covers($a->id, $a->id);

    expect(orgUnitQueries())->toHaveCount(2);
});

it('idOf ignora las unidades borradas', function () {
    $unit = OrgUnit::factory()->create(['code' => 'XYZ']);
    $tree = app(OrgTree::class);

    expect($tree->idOf('XYZ'))->toBe($unit->id)
        ->and($tree->idOf(OrgTree::ROOT))->toBe(OrgUnit::query()->where('code', 'DIRECCION')->value('id'))
        ->and($tree->idOf('NOPE'))->toBeNull();

    $unit->delete();
    app()->forgetScopedInstances();

    expect(app(OrgTree::class)->idOf('XYZ'))->toBeNull();
});
