<?php

use App\Modules\Core\Actions\SendPasswordResetLink;
use App\Modules\Core\Jobs\SendPasswordResetLinkJob;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/*
| «¿Olvidaste tu contraseña?» por el correo institucional (tarea 1.16, AC-16 a AC-18). La pantalla pide el usuario,
| nunca el correo, y responde siempre igual: el trabajo de la cola decide si el usuario puede y le manda el enlace.
| Abrir el enlace (GET) solo muestra el formulario; guardar (POST) pone la contraseña nueva. Un enlace que no sirve
| muestra la misma página, sin decir por qué ni si el usuario existe.
*/

beforeEach(function () {
    $this->staff = User::factory()->create([
        'username' => 'PEAA800101XX1',
        'name' => 'Ana Pérez',
        'email' => 'ana.perez@cdjuarez.tecnm.mx',
        'password' => 'Vieja-clave-2026',
        'must_change_password' => false,
    ]);
});

/**
 * Pide el enlace de `$username` desde la pantalla, con la IP `$ip`.
 */
function requestResetLink(string $username, string $ip = '10.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from(route('password.request'))
        ->post(route('password.email'), ['username' => $username]);
}

/**
 * Manda el enlace de `$user` con el correo falso y devuelve el que le llegó.
 */
function resetLinkFor(User $user): string
{
    Notification::fake();

    app(SendPasswordResetLink::class)->handle($user->username);

    $link = null;
    Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $mail) use (&$link): bool {
        $link = $mail->url;

        return true;
    });

    return $link;
}

/**
 * Lo que el formulario del enlace manda escondido: el token y el usuario, que el enlace lleva en la consulta.
 *
 * @return array{token: string, username: string}
 */
function resetFormFields(string $link): array
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return ['token' => (string) ($query['token'] ?? ''), 'username' => (string) ($query['username'] ?? '')];
}

/**
 * Guarda `$password` con el formulario del enlace `$link`.
 */
function submitNewPassword(string $link, string $password = 'Nueva-clave-2027', ?string $confirmation = null): TestResponse
{
    return test()->from($link)->post(route('password.update'), [
        ...resetFormFields($link),
        'password' => $password,
        'password_confirmation' => $confirmation ?? $password,
    ]);
}

describe('pedir el enlace', function () {
    it('AC-16: la pantalla de acceso lleva a pedir el enlace, que pide el usuario y no el correo', function () {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'))->assertSee('¿Olvidaste tu contraseña?');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertDontSee('type="email"', false)
            ->assertSee('Mandar enlace');
    });

    it('AC-16: manda al correo institucional confirmado del personal un enlace para elegir otra contraseña', function () {
        Notification::fake();

        requestResetLink('PEAA800101XX1')
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'password-reset-link-requested');

        $link = null;
        Notification::assertSentTo($this->staff, PasswordResetLink::class, function (PasswordResetLink $mail, array $channels, User $notifiable) use (&$link): bool {
            $message = $mail->toMail($notifiable);
            $text = implode(' ', [$message->subject, ...$message->introLines, ...$message->outroLines]);
            $link = $mail->url;

            return $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'ana.perez@cdjuarez.tecnm.mx'
                && $message->actionUrl === $mail->url
                && str_starts_with($mail->url, url('/reset-password?'))
                && ! str_contains((string) parse_url($mail->url, PHP_URL_PATH), resetFormFields($mail->url)['token'])
                && str_contains($text, 'PEAA800101XX1')
                && str_contains($text, '60 minutos');
        });

        $log = Activity::where('event', 'password-reset-link-sent')->sole();

        expect($log->subject_id)->toBe($this->staff->id)
            ->and($log->causer_id)->toBeNull()
            ->and($log->description)->toBe('Se mandó a ana.perez@cdjuarez.tecnm.mx un enlace para restablecer la contraseña de PEAA800101XX1')
            ->and(json_encode($log->getAttributes()))->not->toContain(resetFormFields($link)['token']);

        $this->get(route('password.request'))->assertSee('te llegará un enlace');
    });

    it('AC-16: responde igual y no manda nada a quien no puede restablecer por correo', function (array $attributes, string $username) {
        Notification::fake();
        User::factory()->create(['username' => 'ROGJ750505AB2', 'email' => 'jorge.rodriguez@cdjuarez.tecnm.mx']);
        $this->staff->forceFill($attributes)->save();

        $eligible = requestResetLink('ROGJ750505AB2', '10.0.0.1');
        $other = requestResetLink($username, '10.0.0.2');

        expect($other->getStatusCode())->toBe($eligible->getStatusCode())
            ->and($other->headers->get('Location'))->toBe($eligible->headers->get('Location'))
            ->and($other->getSession()->get('status'))->toBe('password-reset-link-requested')
            ->and($other->getSession()->get('errors'))->toBeNull();

        Notification::assertCount(1);
        Notification::assertNotSentTo($this->staff, PasswordResetLink::class);

        expect(Activity::where('event', 'password-reset-link-sent')->where('subject_id', $this->staff->id)->exists())->toBeFalse();
    })->with([
        'usuario que no existe' => [[], 'NOEXISTE'],
        'usuario inactivo' => [['is_active' => false], 'PEAA800101XX1'],
        'usuario sin acceso (sin contraseña)' => [['password' => null], 'PEAA800101XX1'],
        'sin correo' => [['email' => null], 'PEAA800101XX1'],
        'correo de otro dominio' => [['email' => 'ana.perez@gmail.com'], 'PEAA800101XX1'],
        'correo de @itcj.edu.mx' => [['email' => 'ana.perez@itcj.edu.mx'], 'PEAA800101XX1'],
        'dominio que solo contiene al institucional' => [['email' => 'ana@cdjuarez.tecnm.mx.example.com'], 'PEAA800101XX1'],
        'personal con el correo sin confirmar' => [['email_verified_at' => null], 'PEAA800101XX1'],
    ]);

    it('AC-16: la petición no busca al usuario: siempre encola el mismo trabajo, que solo lleva el usuario escrito', function () {
        Queue::fake();
        DB::enableQueryLog();

        requestResetLink('NOEXISTE', '10.0.0.1');
        requestResetLink('PEAA800101XX1', '10.0.0.2');

        $usersQueries = fn () => collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"users"'));

        expect($usersQueries())->toBeEmpty();

        User::query()->where('username', 'NOEXISTE')->first();
        expect($usersQueries())->not->toBeEmpty('El registro de consultas sí ve las de users.');

        Queue::assertPushed(SendPasswordResetLinkJob::class, 2);
        Queue::assertPushed(SendPasswordResetLinkJob::class, fn (SendPasswordResetLinkJob $job): bool => $job->username === 'NOEXISTE');
        Queue::assertPushed(SendPasswordResetLinkJob::class, fn (SendPasswordResetLinkJob $job): bool => $job->username === 'PEAA800101XX1');

        expect(is_subclass_of(PasswordResetLink::class, ShouldQueue::class))->toBeFalse('El correo lleva el token: se manda dentro del trabajo, sin volver a la cola.');
    });

    it('AC-17: limita las solicitudes de un mismo usuario aunque cambie la IP', function () {
        Queue::fake();

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            requestResetLink('PEAA800101XX1', $ip)->assertSessionHasNoErrors();
        }

        requestResetLink('PEAA800101XX1', '10.0.0.4')
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['username' => 'Ya se pidieron varios enlaces. Intenta de nuevo en 15 minutos.']);

        Queue::assertPushed(SendPasswordResetLinkJob::class, 3);
    });

    it('AC-17: limita las solicitudes desde una IP aunque cambie el usuario', function () {
        Queue::fake();
        config(['fortify.password_reset_ip_requests_per_minute' => 2]);

        requestResetLink('AAAA000000001')->assertSessionHasNoErrors();
        requestResetLink('BBBB000000002')->assertSessionHasNoErrors();
        requestResetLink('CCCC000000003')->assertSessionHasErrors('username');

        Queue::assertPushed(SendPasswordResetLinkJob::class, 2);
    });

    it('AC-17: no manda un segundo enlace al mismo usuario dentro del mismo minuto', function () {
        Notification::fake();

        app(SendPasswordResetLink::class)->handle('PEAA800101XX1');
        app(SendPasswordResetLink::class)->handle('PEAA800101XX1');

        Notification::assertSentToTimes($this->staff, PasswordResetLink::class, 1);

        $this->travel(61)->seconds();
        app(SendPasswordResetLink::class)->handle('PEAA800101XX1');

        Notification::assertSentToTimes($this->staff, PasswordResetLink::class, 2);
    });

    it('AC-16: si las reglas aceptan el correo institucional sin confirmar de un usuario (el alumno), le llega el enlace y al restablecer queda confirmado', function () {
        app()->instance(PasswordResetRules::class, new class extends PasswordResetRules
        {
            public function acceptsUnverifiedInstitutionalEmail(User $user): bool
            {
                return $user->username === 'L21110001';
            }
        });
        $student = User::factory()->create(['username' => 'L21110001', 'email' => 'l21110001@cdjuarez.tecnm.mx', 'email_verified_at' => null]);
        $this->staff->forceFill(['email_verified_at' => null])->save();

        Notification::fake();
        app(SendPasswordResetLink::class)->handle('PEAA800101XX1');
        Notification::assertNothingSent();

        $link = resetLinkFor($student);
        submitNewPassword($link)->assertRedirect(route('login'));

        expect($student->fresh()->email_verified_at)->not->toBeNull()
            ->and(Hash::check('Nueva-clave-2027', $student->fresh()->password))->toBeTrue();
    });
});

describe('restablecer', function () {
    it('AC-17: abrir el enlace solo muestra el formulario y no cambia nada', function () {
        $link = resetLinkFor($this->staff);
        $before = $this->staff->fresh()->password;

        $this->get($link)
            ->assertOk()
            ->assertSee('Elige una contraseña nueva')
            ->assertSee('PEAA800101XX1')
            ->assertSee('name="password_confirmation"', false);

        expect($this->staff->fresh()->password)->toBe($before);

        submitNewPassword($link)->assertRedirect(route('login'));
    });

    it('AC-18: pone la contraseña nueva, quita el cambio obligatorio, conserva el correo confirmado, rota recordarme y lleva al login con un aviso', function () {
        $this->staff->forceFill(['must_change_password' => true])->save();
        $before = $this->staff->fresh();
        $link = resetLinkFor($this->staff);

        submitNewPassword($link)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'password-reset');

        $user = $this->staff->fresh();

        expect(Hash::check('Nueva-clave-2027', $user->password))->toBeTrue()
            ->and($user->must_change_password)->toBeFalse()
            ->and($user->email_verified_at->equalTo($before->email_verified_at))->toBeTrue()
            ->and($user->getRememberToken())->not->toBe($before->getRememberToken())
            ->and(DB::table('password_reset_tokens')->count())->toBe(0);

        $this->get(route('login'))->assertSee('Tu contraseña quedó lista');

        $this->post('/login', ['username' => 'PEAA800101XX1', 'password' => 'Nueva-clave-2027']);
        $this->assertAuthenticatedAs($user);
    });

    it('AC-18: deja bitácora a nombre del usuario sin la contraseña ni el token', function () {
        $link = resetLinkFor($this->staff);

        submitNewPassword($link);

        $log = Activity::where('event', 'password-reset-with-link')->sole();
        $stored = json_encode(Activity::all()->map->getAttributes());

        expect($log->subject_id)->toBe($this->staff->id)
            ->and($log->causer_id)->toBe($this->staff->id)
            ->and($log->description)->toBe('PEAA800101XX1 restableció su contraseña con el enlace de su correo')
            ->and($stored)->not->toContain('Nueva-clave-2027')
            ->and($stored)->not->toContain(resetFormFields($link)['token'])
            ->and($stored)->not->toContain($this->staff->fresh()->password);
    });

    it('AC-18: cierra las demás sesiones del usuario', function () {
        $otherDevice = [Auth::guard()->getName() => $this->staff->getKey(), 'password_hash_web' => $this->staff->password];
        $this->withSession($otherDevice)->get('/')->assertOk();

        Auth::forgetGuards();
        $this->flushSession();
        submitNewPassword(resetLinkFor($this->staff))->assertRedirect(route('login'));

        Auth::forgetGuards();
        $this->flushSession();
        $this->withSession($otherDevice)->get('/')->assertRedirect('/login');

        $this->assertGuest();
    });

    it('AC-18: aplica la política de contraseñas y no acepta la contraseña por defecto; el enlace sigue sirviendo', function (Closure $input) {
        $link = resetLinkFor($this->staff);
        [$password, $confirmation] = $input();

        submitNewPassword($link, $password, $confirmation)
            ->assertRedirect($link)
            ->assertSessionHasErrors('password');

        expect(Hash::check('Vieja-clave-2026', $this->staff->fresh()->password))->toBeTrue()
            ->and(Activity::where('event', 'password-reset-with-link')->exists())->toBeFalse();

        submitNewPassword($link)->assertRedirect(route('login'));
    })->with([
        'corta' => [fn (): array => ['Ab1', 'Ab1']],
        'sin números' => [fn (): array => ['SoloLetras', 'SoloLetras']],
        'sin letras' => [fn (): array => ['12345678', '12345678']],
        'sin confirmar' => [fn (): array => ['Nueva-clave-2027', 'Otra-clave-2027']],
        'la contraseña por defecto' => [fn (): array => [app(DefaultPassword::class)->value(), app(DefaultPassword::class)->value()]],
    ]);

    it('AC-17: un enlace vencido, usado, reemplazado, alterado o que ya no es de ese usuario muestra la misma página y no cambia la contraseña', function (Closure $spoil) {
        $link = $spoil($this, resetLinkFor($this->staff));

        $this->get($link)
            ->assertForbidden()
            ->assertSee('Este enlace ya no sirve')
            ->assertDontSee('PEAA800101XX1');

        submitNewPassword($link, 'Otra-clave-2028')
            ->assertForbidden()
            ->assertSee('Este enlace ya no sirve')
            ->assertDontSee('PEAA800101XX1');

        expect(Hash::check('Otra-clave-2028', $this->staff->fresh()->password))->toBeFalse()
            ->and(Activity::where('event', 'password-reset-with-link')->count())->toBeLessThanOrEqual(1);
    })->with([
        'vencido' => [function ($test, string $link): string {
            $test->travel(61)->minutes();

            return $link;
        }],
        'ya usado' => [function ($test, string $link): string {
            submitNewPassword($link)->assertRedirect(route('login'));

            return $link;
        }],
        'reemplazado por uno más nuevo' => [function ($test, string $link): string {
            $test->travel(2)->minutes();
            resetLinkFor($test->staff);

            return $link;
        }],
        'token alterado' => [fn ($test, string $link): string => str_replace(resetFormFields($link)['token'], str_repeat('a', 64), $link)],
        'con el usuario de otra persona' => [function ($test, string $link): string {
            User::factory()->create(['username' => 'ROGJ750505AB2', 'email' => 'jorge.rodriguez@cdjuarez.tecnm.mx']);

            return str_replace('username=PEAA800101XX1', 'username=ROGJ750505AB2', $link);
        }],
        'con un usuario que no existe' => [fn ($test, string $link): string => str_replace('username=PEAA800101XX1', 'username=NOEXISTE', $link)],
        'usuario desactivado' => [function ($test, string $link): string {
            $test->staff->forceFill(['is_active' => false])->save();

            return $link;
        }],
        'el correo cambió' => [function ($test, string $link): string {
            $test->staff->forceFill(['email' => 'ana.perez.nueva@cdjuarez.tecnm.mx'])->save();

            return $link;
        }],
        'el correo ya no está confirmado' => [function ($test, string $link): string {
            $test->staff->forceFill(['email_verified_at' => null])->save();

            return $link;
        }],
    ]);

    it('AC-17: sirve aunque haya otra sesión abierta en esa computadora, y no la cambia', function () {
        $admin = User::factory()->admin()->create();
        $link = resetLinkFor($this->staff);

        $this->actingAs($admin)->get($link)->assertOk()->assertSee('Elige una contraseña nueva');
        submitNewPassword($link)->assertRedirect(route('login'));

        expect(Hash::check('Nueva-clave-2027', $this->staff->fresh()->password))->toBeTrue();
        $this->assertAuthenticatedAs($admin);
    });
});
