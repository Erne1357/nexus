<?php

use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;

/*
| AC-04: el estado del acceso de un usuario sale de su contraseña, de la invitación y del cambio obligatorio. Sin
| contraseña no tiene acceso (AC-01), aunque ya se le haya invitado.
*/

it('AC-04: sin acceso, invitación enviada, debe cambiar su contraseña o con acceso, con su tono', function () {
    $user = fn (?string $password, ?string $invitedAt, bool $mustChange): User => (new User)->setRawAttributes([
        'password' => $password,
        'access_invited_at' => $invitedAt === null ? null : CarbonImmutable::parse($invitedAt),
        'must_change_password' => $mustChange,
    ]);

    expect(UserAccess::of($user(null, null, true)))->toBe(UserAccess::None)
        ->and(UserAccess::of($user(null, '2026-11-11 09:00:00-07', true)))->toBe(UserAccess::Invited)
        ->and(UserAccess::of($user('$2y$12$hash', null, true)))->toBe(UserAccess::MustChangePassword)
        ->and(UserAccess::of($user('$2y$12$hash', '2026-11-11 09:00:00-07', true)))->toBe(UserAccess::MustChangePassword)
        ->and(UserAccess::of($user('$2y$12$hash', null, false)))->toBe(UserAccess::Granted)
        ->and(UserAccess::None->label())->toBe('Sin acceso')
        ->and(UserAccess::Invited->label())->toBe('Invitación enviada')
        ->and(UserAccess::MustChangePassword->label())->toBe('Debe cambiar su contraseña')
        ->and(UserAccess::Granted->label())->toBe('Con acceso')
        ->and(UserAccess::None->tone())->toBe(StatusTone::Neutral)
        ->and(UserAccess::Invited->tone())->toBe(StatusTone::Info)
        ->and(UserAccess::MustChangePassword->tone())->toBe(StatusTone::Warning)
        ->and(UserAccess::Granted->tone())->toBe(StatusTone::Success);
});

it('AC-01: solo tiene acceso quien tiene contraseña', function () {
    expect(UserAccess::None->hasAccess())->toBeFalse()
        ->and(UserAccess::Invited->hasAccess())->toBeFalse()
        ->and(UserAccess::MustChangePassword->hasAccess())->toBeTrue()
        ->and(UserAccess::Granted->hasAccess())->toBeTrue();
});
