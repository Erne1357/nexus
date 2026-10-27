<?php

use App\Modules\Core\Actions\RecordLogin;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;

it('anota last_login_at de un usuario activo', function () {
    Carbon::setTestNow('2026-11-02 08:15:00');
    $user = User::factory()->create(['last_login_at' => null]);

    app(RecordLogin::class)->handle($user);

    expect($user->fresh()->last_login_at->toDateTimeString())->toBe('2026-11-02 08:15:00');
});

it('no anota last_login_at de un usuario inactivo', function () {
    $user = User::factory()->create(['is_active' => false, 'last_login_at' => null]);

    app(RecordLogin::class)->handle($user);

    expect($user->fresh()->last_login_at)->toBeNull();
});
