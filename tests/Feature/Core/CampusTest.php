<?php

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
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

it('guarda la modalidad y expone el perfil de flujo de settings', function () {
    $campus = Campus::factory()->create([
        'modality' => Modality::Mixta,
        'settings' => ['days' => [1, 2, 3, 4, 5, 6], 'staffing_mode' => 'central'],
    ])->fresh();

    expect($campus->modality)->toBe(Modality::Mixta)
        ->and($campus->profile)->toBeInstanceOf(CampusProfile::class)
        ->and($campus->profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($campus->profile->staffingMode)->toBe(StaffingMode::Central)
        ->and($campus->profile->usesRooms)->toBeTrue();
});

it('usa el perfil por omisión cuando el campus se crea sin settings', function () {
    $campus = Campus::create(['code' => 'ENL', 'name' => 'EnLínea', 'modality' => Modality::EnLinea])->fresh();

    expect($campus->settings)->toBe([])
        ->and($campus->profile->toArray())->toBe(CampusProfile::DEFAULTS);
});

it('lee el perfil nuevo en la misma instancia después de cambiar settings', function () {
    $campus = Campus::factory()->create();
    expect($campus->profile->usesRooms)->toBeTrue();

    $campus->settings = [...$campus->settings, 'uses_rooms' => false];

    expect($campus->profile->usesRooms)->toBeFalse();
});
