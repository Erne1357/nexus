<?php

use App\Modules\Core\Actions\ResetForgottenPassword;
use App\Modules\Core\Actions\SendPasswordResetLink;
use App\Modules\Core\Actions\SendUserPasswordReset;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SendUserPasswordReset, «Restablecer contraseña» desde el panel (CF-08): el admin manda el enlace de AC-16 al correo
| institucional de un usuario activo con contraseña, con las mismas reglas (PasswordResetRules) y los mismos enlaces
| (PasswordResetTokens) que «¿Olvidaste tu contraseña?»; la bitácora dice quién lo pidió y el admin ve el resultado.
| El admin nunca ve ni pone una contraseña.
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');
    Notification::fake();

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create([
        'name' => 'Rosa Ruiz',
        'username' => 'RURR800101AB1',
        'email' => 'rosa.ruiz@cdjuarez.tecnm.mx',
        'password' => 'Clave-propia-2026',
    ]);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function sendPanelReset(User $user): string
{
    return app(SendUserPasswordReset::class)->handle($user, test()->admin);
}

it('CF-08: manda el enlace de AC-16 al correo institucional y la bitácora dice quién lo pidió, sin el token', function () {
    expect(sendPanelReset($this->rosa))->toBe('rosa.ruiz@cdjuarez.tecnm.mx');

    $link = null;
    Notification::assertSentTo($this->rosa, PasswordResetLink::class, function (PasswordResetLink $mail) use (&$link): bool {
        $link = $mail->url;

        return $mail->username === 'RURR800101AB1';
    });

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect(app(PasswordResetTokens::class)->opens($this->rosa->fresh(), $query['token']))->toBeTrue();

    $entry = activitySince()->sole();

    expect($entry->event)->toBe('password-reset-link-issued')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties->get('email'))->toBe('rosa.ruiz@cdjuarez.tecnm.mx')
        ->and($entry->description)->toBe('Mandó a RURR800101AB1 el enlace para restablecer su contraseña, que va a rosa.ruiz@cdjuarez.tecnm.mx')
        ->and(json_encode($entry->toArray()))->not->toContain($query['token']);
});

it('CF-08: no lo manda, y dice por qué, si la cuenta no puede restablecer', function (Closure $arrange, string $reason) {
    $arrange($this->rosa);

    expect(fn () => sendPanelReset($this->rosa->fresh()))->toThrow(ValidationException::class, $reason);

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(activitySince()->count())->toBe(0);
})->with([
    'dada de baja' => [fn (User $user) => $user->forceFill(['is_active' => false])->save(), UserAccountRules::RESET_INACTIVE],
    'sin contraseña' => [fn (User $user) => $user->forceFill(['password' => null])->save(), UserAccountRules::RESET_NO_PASSWORD],
    'sin correo' => [fn (User $user) => $user->forceFill(['email' => null])->save(), UserAccountRules::RESET_NO_EMAIL],
    'correo de otro dominio' => [fn (User $user) => $user->forceFill(['email' => 'rosa@gmail.com'])->save(), UserAccountRules::RESET_NO_EMAIL],
]);

it('CF-08: el enlace del admin sale aunque el correo del personal no esté confirmado, abre, y al usarlo lo confirma (AC-18)', function () {
    $this->rosa->forceFill(['email_verified_at' => null])->save();

    sendPanelReset($this->rosa->fresh());

    $link = null;
    Notification::assertSentTo($this->rosa, PasswordResetLink::class, function (PasswordResetLink $mail) use (&$link): bool {
        $link = $mail->url;

        return true;
    });
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect(app(PasswordResetTokens::class)->opens($this->rosa->fresh(), $query['token']))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->sole()->issued_by)->toBe($this->admin->id);

    $user = app(ResetForgottenPassword::class)->handle('RURR800101AB1', $query['token'], ['password' => 'Nueva-clave-2027', 'password_confirmation' => 'Nueva-clave-2027']);

    expect($user)->not->toBeNull()
        ->and($this->rosa->fresh()->email_verified_at)->not->toBeNull();
});

it('AC-16: «¿Olvidaste tu contraseña?» sigue pidiendo el correo confirmado del personal, y su enlace no abre sin él', function () {
    $this->rosa->forceFill(['email_verified_at' => null])->save();
    $rosa = $this->rosa->fresh();
    $tokens = app(PasswordResetTokens::class);

    expect(app(SendPasswordResetLink::class)->handle('RURR800101AB1'))->toBeFalse()
        ->and(app(PasswordResetRules::class)->allows($rosa))->toBeFalse()
        ->and(app(PasswordResetRules::class)->allows($rosa, issuedByAdmin: true))->toBeTrue();

    Notification::assertNothingSent();

    $selfService = $tokens->create($rosa);

    expect(DB::table('password_reset_tokens')->sole()->issued_by)->toBeNull()
        ->and($tokens->opens($rosa, $selfService))->toBeFalse();
});

it('AC-17: un enlace del autoservicio reemplaza al del admin, y con él vuelve a pedir el correo confirmado', function () {
    $this->rosa->forceFill(['email_verified_at' => null])->save();
    $tokens = app(PasswordResetTokens::class);

    sendPanelReset($this->rosa->fresh());
    $this->travel(61)->seconds();
    $selfService = $tokens->create($this->rosa->fresh());

    expect(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->sole()->issued_by)->toBeNull()
        ->and($tokens->opens($this->rosa->fresh(), $selfService))->toBeFalse();
});

it('CF-S4: al alumno sí, con su correo institucional sin confirmar', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['email' => 'l19110001@cdjuarez.tecnm.mx', 'email_verified_at' => null]);

    expect(sendPanelReset($student))->toBe('l19110001@cdjuarez.tecnm.mx');

    Notification::assertSentTo($student, PasswordResetLink::class);
});

it('AC-17: un enlace por usuario por minuto', function () {
    sendPanelReset($this->rosa);

    expect(fn () => sendPanelReset($this->rosa))->toThrow(ValidationException::class, UserAccountRules::RESET_RECENT);

    Notification::assertSentToTimes($this->rosa, PasswordResetLink::class, 1);

    $this->travel(61)->seconds();

    sendPanelReset($this->rosa);

    Notification::assertSentToTimes($this->rosa, PasswordResetLink::class, 2);
});

it('si el correo no sale, borra el enlace para que se pueda pedir otro y la bitácora lo dice', function () {
    Notification::swap(new class
    {
        public function send(mixed $notifiables, mixed $notification): void
        {
            throw new RuntimeException('El servidor de correo no responde');
        }

        public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void
        {
            $this->send($notifiables, $notification);
        }
    });

    expect(fn () => sendPanelReset($this->rosa))->toThrow(RuntimeException::class, 'El servidor de correo no responde');

    expect(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(activitySince()->orderBy('id')->pluck('event')->all())->toBe(['password-reset-link-issued', 'password-reset-link-failed'])
        ->and(activitySince()->where('event', 'password-reset-link-failed')->sole()->causer_id)->toBe($this->admin->id);
});
