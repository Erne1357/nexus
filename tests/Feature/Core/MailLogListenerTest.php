<?php

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
| Registro de correos (CF-S8): cada correo que sale queda con destinatario, asunto, tipo, usuario y hora, sin el cuerpo,
| que lleva enlaces con token. MessageSent solo se dispara con un envío de verdad, así que las pruebas usan el
| mailer `array` y no Notification::fake(). Los destinatarios son de un dominio de prueba que se declara institucional,
| para que la guardia del correo (CE-01) los deje salir.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domains' => ['itcj.test']]);
});

final class MailLogProbeNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Asunto de prueba')->line('Enlace secreto: https://nexus.test/token-12345');
    }
}

final class MailLogProbeMailable extends Mailable
{
    public function build(): static
    {
        return $this->subject('Correo suelto')->html('<p>Cuerpo con token-98765</p>');
    }
}

it('CF-S8: anota la notificación mandada a un correo suelto, sin usuario y sin el cuerpo', function () {
    NotificationFacade::route('mail', 'alguien@itcj.test')->notify(new MailLogProbeNotification);

    $log = MailLog::query()->sole();

    expect($log->to)->toBe('alguien@itcj.test')
        ->and($log->subject)->toBe('Asunto de prueba')
        ->and($log->kind)->toBe('MailLogProbeNotification')
        ->and($log->status)->toBe(MailStatus::Sent)
        ->and($log->user_id)->toBeNull()
        ->and($log->error)->toBeNull()
        ->and($log->sent_at)->not->toBeNull()
        ->and(json_encode($log->getAttributes()))->not->toContain('token-12345');
});

it('CF-S8: anota la notificación de un usuario con su id', function () {
    $user = User::factory()->create(['email' => 'Maria@itcj.test']);

    $user->notify(new MailLogProbeNotification);

    $log = MailLog::query()->sole();

    expect($log->user_id)->toBe($user->id)
        ->and($log->to)->toBe('Maria@itcj.test');
});

it('CF-S8: anota un mailable con su clase corta como tipo', function () {
    Mail::to('otro@itcj.test')->send(new MailLogProbeMailable);

    $log = MailLog::query()->sole();

    expect($log->kind)->toBe('MailLogProbeMailable')
        ->and($log->subject)->toBe('Correo suelto')
        ->and($log->user_id)->toBeNull()
        ->and(json_encode($log->getAttributes()))->not->toContain('token-98765');
});

final class MailLogQueuedProbe extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Asunto en cola')->line('Enlace: https://nexus.test/x?token=abc123');
    }
}

final class MailLogQueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build(): static
    {
        return $this->subject('Mailable en cola')->html('<p>hola</p>');
    }
}

/**
 * Un transporte de correo que siempre falla, para que el envío lance de verdad.
 */
function failingMailer(): void
{
    Mail::extend('boom', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Conexión rechazada por el servidor');
        }

        public function __toString(): string
        {
            return 'boom';
        }
    });

    config(['mail.mailers.boom' => ['transport' => 'boom'], 'mail.default' => 'boom']);
    Mail::purge();
}

it('CF-S8: anota como fallida la notificación en cola cuyo envío lanza, con su error', function () {
    failingMailer();
    $user = User::factory()->create(['email' => 'falla@itcj.test']);

    expect(fn () => $user->notify(new MailLogQueuedProbe))->toThrow(TransportException::class);

    $log = MailLog::query()->sole();

    expect($log->status)->toBe(MailStatus::Failed)
        ->and($log->error)->toBe('Conexión rechazada por el servidor')
        ->and($log->to)->toBe('falla@itcj.test')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->kind)->toBe('MailLogQueuedProbe')
        ->and($log->subject)->toBe('Asunto en cola');
});

it('CF-S8: anota como fallida la notificación en cola a un correo suelto', function () {
    failingMailer();

    expect(fn () => NotificationFacade::route('mail', 'suelto@itcj.test')->notify(new MailLogQueuedProbe))->toThrow(TransportException::class);

    expect(MailLog::query()->sole())->to->toBe('suelto@itcj.test')->status->toBe(MailStatus::Failed);
});

it('CF-S8: anota como fallido el mailable en cola cuyo envío lanza', function () {
    failingMailer();

    expect(fn () => Mail::to('otro@itcj.test')->queue(new MailLogQueuedMailable))->toThrow(TransportException::class);

    $log = MailLog::query()->sole();

    expect($log->kind)->toBe('MailLogQueuedMailable')
        ->and($log->subject)->toBe('') // el asunto de un mailable se arma al enviar
        ->and($log->to)->toBe('otro@itcj.test')
        ->and($log->status)->toBe(MailStatus::Failed);
});

it('CF-S8: no anota el fallo de un trabajo que no es correo', function () {
    $job = new class implements ShouldQueue
    {
        public function handle(): void
        {
            throw new RuntimeException('otra cosa');
        }
    };

    expect(fn () => dispatch($job))->toThrow(RuntimeException::class);
    expect(MailLog::query()->count())->toBe(0);
});

it('CF-S8: si anotar el correo enviado falla, se reporta y el envío no se rompe', function () {
    Exceptions::fake();
    MailLog::creating(fn () => throw new RuntimeException('no se pudo anotar'));

    NotificationFacade::route('mail', 'alguien@itcj.test')->notify(new MailLogProbeNotification);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'no se pudo anotar');
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});
