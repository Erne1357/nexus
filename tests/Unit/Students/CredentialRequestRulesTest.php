<?php

use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Rules\CredentialRequestSettings;

test('CO-07: el estado abierto es solicitada, citada o lista', function () {
    $open = collect(CredentialRequestStatus::cases())->filter->isOpen()->map->value->values()->all();

    expect($open)->toBe(['solicitada', 'citada', 'lista']);
});

test('CO-S1: los plazos valen 0 (sin límite) si falta el valor o no es válido', function () {
    expect(CredentialRequestSettings::fromValues([]))->toEqual(new CredentialRequestSettings(0, 0))
        ->and(CredentialRequestSettings::fromValues(['students.credential_reposition_days' => 180, 'students.credential_photo_days' => -3]))->toEqual(new CredentialRequestSettings(180, 0))
        ->and(CredentialRequestSettings::fromValues(['students.credential_photo_days' => 99999]))->toEqual(new CredentialRequestSettings(0, 0));
});
