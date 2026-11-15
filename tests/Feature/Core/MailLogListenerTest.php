<?php

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
| Registro de correos (CF-S8): cada correo que sale queda con destinatario, asunto, tipo, usuario y hora, sin el cuerpo,
| que lleva enlaces con token. MessageSent solo se dispara con un envío de verdad, así que las pruebas usan el
| mailer `array` y no Notification::fake().
*/

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

it('CF-S8: anota como fallida la notificación por correo que falló, con su error', function () {
    $user = User::factory()->create(['email' => 'falla@itcj.test']);

    event(new NotificationFailed($user, new MailLogProbeNotification, 'mail', ['message' => 'Conexión rechazada']));

    $log = MailLog::query()->sole();

    expect($log->status)->toBe(MailStatus::Failed)
        ->and($log->error)->toBe('Conexión rechazada')
        ->and($log->to)->toBe('falla@itcj.test')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->subject)->toBe('Asunto de prueba');
});

it('CF-S8: no anota la falla de un canal que no es correo', function () {
    $user = User::factory()->create();

    event(new NotificationFailed($user, new MailLogProbeNotification, 'database', ['message' => 'x']));

    expect(MailLog::query()->count())->toBe(0);
});
