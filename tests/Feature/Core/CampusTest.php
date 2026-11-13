<?php

use App\Modules\Core\Models\Campus;
use Illuminate\Database\UniqueConstraintViolationException;

it('no permite dos campus con el mismo código', function () {
    Campus::factory()->create(['code' => 'C2']);

    expect(fn () => Campus::factory()->create(['code' => 'C2']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('mantiene reservado el código de un campus borrado', function () {
    Campus::factory()->create(['code' => 'C2'])->delete();

    expect(fn () => Campus::factory()->create(['code' => 'C2']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('borra un campus con soft delete y conserva el registro', function () {
    $campus = Campus::factory()->create();

    $campus->delete();

    expect(Campus::find($campus->id))->toBeNull()
        ->and(Campus::withTrashed()->find($campus->id)->trashed())->toBeTrue();
});

it('MS3: el campus es solo el lugar: código, nombre y estado, sin modalidad ni perfil de flujo', function () {
    $campus = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus Nueve'])->fresh();

    expect(array_keys($campus->getAttributes()))->not->toContain('modality', 'settings', 'modality_id')
        ->and($campus->getFillable())->toBe(['code', 'name', 'is_active']);
});
