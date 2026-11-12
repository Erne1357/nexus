<?php

use App\Modules\Core\Actions\CancelAccessInvitation;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| La invitación de acceso (tarea 1.16, AC-07 y AC-08). SendAccessInvitation manda al correo institucional un enlace
| firmado que vence a los 7 días, con el usuario y la contraseña por defecto. Abrir el enlace (GET) solo muestra la
| página con el botón, porque los filtros de correo abren los enlaces solos; el botón (POST) habilita al usuario. Un
| enlace vencido, alterado, usado o de un correo que ya cambió muestra la misma página, sin decir si el usuario existe.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->user = User::factory()->create(['username' => 'ABCD800101XX1', 'name' => 'Ana Pérez', 'email' => null, 'password' => null, 'must_change_password' => true]);
});

/**
 * Invita a `$user` a `$email` con el correo falso y devuelve el enlace que se mandó.
 */
function invitationLink(User $user, string $email = 'ana.perez@cdjuarez.tecnm.mx'): string
{
    Notification::fake();

    app(SendAccessInvitation::class)->handle($user, $email);

    $link = null;
    Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation) use (&$link): bool {
        $link = $invitation->url;

        return true;
    });

    return $link;
}

describe('enviar', function () {
    it('AC-07: pone el correo sin verificar, marca la invitación y manda usuario, contraseña por defecto y enlace al correo institucional', function () {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        app(SendAccessInvitation::class)->handle($this->user, 'Ana.Perez@cdjuarez.tecnm.mx', $this->admin);

        $user = $this->user->fresh();

        expect($user->email)->toBe('ana.perez@cdjuarez.tecnm.mx')
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->access_invited_at)->not->toBeNull()
            ->and(UserAccess::of($user))->toBe(UserAccess::Invited);

        Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool {
            $mail = $invitation->toMail($notifiable);
            $text = implode(' ', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

            return $notifiable->routes['mail'] === 'ana.perez@cdjuarez.tecnm.mx'
                && $channels === ['mail']
                && $mail->actionUrl === $invitation->url
                && str_contains($invitation->url, '/access/')
                && str_contains($text, 'ABCD800101XX1')
                && str_contains($text, app(DefaultPassword::class)->value())
                && str_contains($text, 'cambiar la contraseña');
        });

        $log = Activity::where('event', 'access-invited')->sole();

        expect($log->causer_id)->toBe($this->admin->id)
            ->and($log->description)->toBe('Invitó a ABCD800101XX1 a Nexus en ana.perez@cdjuarez.tecnm.mx')
            ->and(json_encode([$log->attribute_changes, $log->properties]))->not->toContain(app(DefaultPassword::class)->value());
    });

    it('AC-07: el correo va cifrado en la cola, porque lleva un enlace vivo', function () {
        expect(new AccessInvitation('ABCD800101XX1', 'Ana', 'https://nexus.test/access/x', now()->toImmutable()))->toBeInstanceOf(ShouldBeEncrypted::class);
    });

    it('AC-07: si el correo se escribe en el log o se guarda en memoria, no lleva la contraseña', function (string $mailer) {
        config(['mail.default' => $mailer]);
        $invitation = new AccessInvitation('ABCD800101XX1', 'Ana', 'https://nexus.test/access/x', now()->toImmutable());
        $mail = $invitation->toMail(new AnonymousNotifiable);
        $text = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

        expect($text)->not->toContain(app(DefaultPassword::class)->value())
            ->and($text)->toContain('Tu contraseña inicial te la da tu jefe de departamento o la administración de Nexus.');
    })->with(['log', 'array']);

    it('AC-07: no invita a un usuario con un puesto distinto de docente, vigente o por empezar: a los jefes los habilita el admin', function (array $assignment) {
        Notification::fake();
        RoleAssignment::factory()->for($this->user)->create($assignment);

        expect(fn () => app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx'))
            ->toThrow(ValidationException::class, 'Tiene un puesto además de docente: le da acceso el administrador.');

        expect($this->user->fresh()->access_invited_at)->toBeNull();
        Notification::assertNothingSent();
    })->with([
        'vigente' => [['role' => Role::Admin, 'valid_from' => '2026-01-01']],
        'por empezar' => [['role' => Role::Admin, 'valid_from' => '2099-01-01']],
    ]);

    it('AC-07: un puesto que ya terminó no impide invitarlo', function () {
        Notification::fake();
        RoleAssignment::factory()->for($this->user)->create(['role' => Role::Admin, 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31']);

        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');

        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('AC-07: dejar sin efecto la invitación también quita el correo sin verificar que puso', function () {
        Notification::fake();
        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');

        app(CancelAccessInvitation::class)->handle($this->user);

        expect($this->user->fresh())->access_invited_at->toBeNull()->email->toBeNull();
    });

    it('AC-07: no invita si otro usuario ya tiene ese correo, sin importar mayúsculas', function () {
        Notification::fake();
        User::factory()->create(['email' => 'ana.perez@cdjuarez.tecnm.mx']);

        expect(fn () => app(SendAccessInvitation::class)->handle($this->user, 'ANA.PEREZ@cdjuarez.tecnm.mx'))
            ->toThrow(ValidationException::class, 'Otro usuario ya tiene el correo ana.perez@cdjuarez.tecnm.mx.');

        expect($this->user->fresh()->access_invited_at)->toBeNull();
        Notification::assertNothingSent();
    });

    it('AC-07: no invita a quien ya tiene acceso ni a un usuario inactivo', function (array $state) {
        Notification::fake();
        $this->user->forceFill($state)->save();

        expect(fn () => app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx'))
            ->toThrow(ValidationException::class);

        Notification::assertNothingSent();
    })->with([
        'con contraseña' => [['password' => 'otra-clave-123']],
        'inactivo' => [['is_active' => false]],
    ]);

    it('AC-07: sin NEXUS_DEFAULT_PASSWORD falla antes de escribir', function () {
        Notification::fake();
        config(['auth.default_password' => null]);

        expect(fn () => app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx'))
            ->toThrow(RuntimeException::class, 'NEXUS_DEFAULT_PASSWORD');

        expect($this->user->fresh()->access_invited_at)->toBeNull();
        Notification::assertNothingSent();
    });
});

describe('aceptar', function () {
    it('AC-08: abrir el enlace muestra la página con el botón y no habilita a nadie', function () {
        $link = invitationLink($this->user);

        $this->get($link)->assertOk()->assertSee('Activar mi acceso')->assertSee('ABCD800101XX1');

        expect($this->user->fresh()->password)->toBeNull();
    });

    it('AC-08: el botón habilita con la contraseña por defecto, cambio obligatorio y correo verificado, inicia su sesión y lo lleva a cambiar la contraseña', function () {
        $link = invitationLink($this->user);

        $this->post($link)->assertRedirect(route('user-password.edit'));
        $this->assertAuthenticatedAs($this->user->fresh());

        $user = $this->user->fresh();
        $log = Activity::where('event', 'access-accepted')->sole();

        expect(Hash::check(app(DefaultPassword::class)->value(), $user->password))->toBeTrue()
            ->and($user->must_change_password)->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($log->causer_id)->toBe($user->id)
            ->and($log->description)->toBe('ABCD800101XX1 activó su acceso con la invitación')
            ->and(json_encode([$log->attribute_changes, $log->properties]))->not->toContain(app(DefaultPassword::class)->value());

        $this->get(route('home'))->assertRedirect(route('user-password.edit'));
    });

    it('AC-08: un enlace vencido, alterado, ya usado, reenviado o de un usuario dado de baja muestra la misma página y no habilita', function (Closure $spoil) {
        $link = invitationLink($this->user);
        $link = $spoil($this, $link);

        foreach (['get', 'post'] as $method) {
            $this->{$method}($link)
                ->assertForbidden()
                ->assertSee('Este enlace ya no sirve')
                ->assertDontSee('ABCD800101XX1');
        }

        expect($this->user->fresh()->password === null || $this->user->fresh()->is_active)->toBeTrue()
            ->and(Activity::where('event', 'access-accepted')->count())->toBeLessThanOrEqual(1);
    })->with([
        'vencido' => [function ($test, string $link): string {
            $test->travel(7)->days();
            $test->travel(1)->minutes();

            return $link;
        }],
        'alterado' => [fn ($test, string $link): string => str_replace('signature=', 'signature=0', $link)],
        'ya usado' => [function ($test, string $link): string {
            $test->post($link);
            auth()->logout();

            return $link;
        }],
        'reenviado' => [function ($test, string $link): string {
            $test->travel(1)->minutes();
            invitationLink($test->user);

            return $link;
        }],
        'reenviado en el mismo segundo' => [function ($test, string $link): string {
            $test->freezeSecond();
            invitationLink($test->user);

            return $link;
        }],
        'usuario dado de baja' => [function ($test, string $link): string {
            $test->user->forceFill(['is_active' => false])->save();

            return $link;
        }],
    ]);

    it('AC-08: sirve aunque haya otra sesión abierta en esa computadora: la cierra y abre la del invitado', function () {
        $link = invitationLink($this->user);

        $this->actingAs($this->admin)->get($link)->assertOk()->assertSee('Activar mi acceso');
        $this->post($link)->assertRedirect(route('user-password.edit'));

        expect($this->user->fresh()->password)->not->toBeNull();
        $this->assertAuthenticatedAs($this->user->fresh());
    });

    it('AC-08: un enlace a un usuario que no existe responde igual que uno vencido', function () {
        $link = invitationLink($this->user);
        $missing = str_replace($this->user->id, fakeKey(99), $link);

        $this->get($missing)->assertForbidden()->assertSee('Este enlace ya no sirve');
    });
});
