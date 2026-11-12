<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\LoginThrottle;
use App\Modules\Core\Support\SiiNip;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/*
| AC-14: el login frena a quien adivina contraseñas (en especial un NIP de 4 dígitos) sin bloquear nunca la cuenta
| («solo frenar», confirmado el 11 de noviembre de 2026; los números son supuestos), y un fallo tarda lo mismo exista o
| no el usuario y sea cual sea el costo de su hash.
*/

/**
 * Un intento de acceso desde `$ip`.
 */
function loginFrom(string $ip, string $username, string $password): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->from('/login')->post('/login', ['username' => $username, 'password' => $password]);
}

it('AC-14: tras cinco fallos el usuario espera un minuto aunque cambie de IP, exista o no, y entrar bien borra la cuenta', function () {
    $this->freezeTime();
    $user = User::factory()->create(['username' => '19110020']);

    foreach (['19110020', '19119998'] as $username) {
        foreach (range(1, 5) as $attempt) {
            loginFrom("10.0.{$attempt}.9", $username, 'incorrecta')->assertSessionHasErrors(['username' => __('auth.failed')]);
        }

        loginFrom('10.0.9.9', $username, 'password')->assertSessionHasErrors(['username' => __('auth.throttle', ['seconds' => 60])]);
        $this->assertGuest();
    }

    $this->travel(61)->seconds();
    loginFrom('10.0.9.9', '19110020', 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    expect(app(LoginThrottle::class)->waitFor('19110020'))->toBe(0);
});

it('AC-14: las esperas crecen: 1 minuto desde el 5.º fallo, 15 desde el 10.º, 1 hora desde el 20.º y 6 horas desde el 30.º', function () {
    $this->freezeTime();
    $throttle = app(LoginThrottle::class);
    $waits = [];

    foreach (range(1, 31) as $failure) {
        $throttle->failed('21110001');
        $waits[$failure] = $throttle->waitFor('21110001');
    }

    expect(array_intersect_key($waits, array_flip([4, 5, 9, 10, 19, 20, 29, 30, 31])))->toBe([4 => 0, 5 => 60, 9 => 60, 10 => 900, 19 => 900, 20 => 3600, 29 => 3600, 30 => 21600, 31 => 21600])
        ->and($throttle->waitFor('otro'))->toBe(0);

    $throttle->succeeded('21110001');
    $throttle->failed('21110001');

    expect($throttle->waitFor('21110001'))->toBe(0);
});

it('AC-14: más de 300 fallos contra cuentas con NIP en 10 minutos pausan 10 minutos los inicios con NIP y se reporta una vez', function () {
    Exceptions::fake();
    $this->freezeTime();
    User::factory()->create(['username' => '21110001', 'password' => SiiNip::hash('4821'), 'password_origin' => PasswordOrigin::SiiNip]);
    $staff = User::factory()->create(['username' => 'ABCD800101XX1']);
    $throttle = app(LoginThrottle::class);

    foreach (range(1, LoginThrottle::NIP_FAILURES + 5) as $failure) {
        $throttle->nipFailed();
    }

    expect($throttle->nipPaused())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Se pausaron 10 minutos los inicios de sesión con el NIP del SII'));
    Exceptions::assertReportedCount(1);

    loginFrom('10.0.0.1', '21110001', '4821')->assertSessionHasErrors(['username' => __('auth.failed')]);
    $this->assertGuest();
    loginFrom('10.0.0.1', 'ABCD800101XX1', 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($staff);
    auth()->logout();

    $this->travel(LoginThrottle::NIP_PAUSE + 1)->seconds();
    loginFrom('10.0.0.2', '21110001', '4821')->assertRedirect('/');
});

it('AC-14: un fallo contra una cuenta con NIP cuenta para el tope global; contra el personal, no', function () {
    User::factory()->create(['username' => '21110001', 'password' => SiiNip::hash('4821'), 'password_origin' => PasswordOrigin::SiiNip]);
    User::factory()->create(['username' => 'ABCD800101XX1']);
    $window = 'login-throttle|nip-failures|'.intdiv(now()->getTimestamp(), LoginThrottle::NIP_WINDOW);

    loginFrom('10.0.0.1', 'ABCD800101XX1', 'incorrecta');
    loginFrom('10.0.0.1', '21110001', '1111');

    expect((int) cache()->get($window))->toBe(1);
});

it('AC-14: limita los intentos por red, /24 en IPv4 y /64 en IPv6, sin importar el usuario ni la IP', function (array $sameNetwork, string $otherNetwork) {
    $this->freezeTime();
    config(['fortify.network_attempts_per_minute' => 3]);
    $last = array_pop($sameNetwork);

    foreach ($sameNetwork as $index => $ip) {
        loginFrom($ip, "1911100{$index}", 'incorrecta')->assertSessionHasErrors(['username' => __('auth.failed')]);
    }

    loginFrom($last, '19110009', 'incorrecta')->assertSessionHasErrors(['username' => __('auth.throttle', ['seconds' => 60])]);
    loginFrom($otherNetwork, '19110008', 'incorrecta')->assertSessionHasErrors(['username' => __('auth.failed')]);
})->with([
    'IPv4' => [['10.9.9.1', '10.9.9.2', '10.9.9.3', '10.9.9.4'], '10.9.8.1'],
    'IPv6' => [['2001:db8::1', '2001:db8::2', '2001:db8::3', '2001:db8::4'], '2001:db8:0:1::1'],
]);

it('AC-14: todo fallo compara exactamente una vez con cada costo de bcrypt, exista o no el usuario', function (Closure $username) {
    config(['hashing.bcrypt.rounds' => 11]);
    Hash::forgetDrivers();
    $real = app('hash');
    $costs = new ArrayObject;
    Hash::swap(new class($real, $costs) implements Hasher
    {
        public function __construct(private readonly object $real, private readonly ArrayObject $costs) {}

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
        {
            $this->costs[] = password_get_info((string) $hashedValue)['options']['cost'] ?? null;

            return $this->real->check($value, $hashedValue, $options);
        }

        public function info($hashedValue): array
        {
            return $this->real->info($hashedValue);
        }

        public function make(#[SensitiveParameter] $value, array $options = []): string
        {
            return $this->real->make($value, $options);
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return $this->real->needsRehash($hashedValue, $options);
        }

        public function __call(string $method, array $arguments): mixed
        {
            return $this->real->{$method}(...$arguments);
        }
    });

    loginFrom('10.0.0.1', $username(), 'Incorrecta-1')->assertSessionHasErrors(['username' => __('auth.failed')]);

    $seen = $costs->getArrayCopy();
    sort($seen);
    expect($seen)->toBe([10, 11]);
})->with([
    'no existe' => fn (): string => '19119999',
    'personal con contraseña equivocada' => fn (): string => User::factory()->create(['password' => Hash::make('Correcta-2026')])->username,
    'alumno con NIP equivocado' => fn (): string => User::factory()->create(['password' => SiiNip::hash('4821'), 'password_origin' => PasswordOrigin::SiiNip])->username,
    'sin contraseña' => fn (): string => User::factory()->create(['password' => null, 'password_origin' => null])->username,
    'inactivo con su contraseña' => fn (): string => User::factory()->create(['password' => Hash::make('Incorrecta-1'), 'is_active' => false])->username,
]);
