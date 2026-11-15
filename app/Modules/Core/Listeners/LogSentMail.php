<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Actions\RecordMail;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;

/**
 * Anota en el registro de correos (CF-S8) cada correo que sale, venga de una notificación o de un mailable. Lleva la
 * clase corta de quien lo armó como tipo y los destinatarios (Para, con copia y copia oculta); el cuerpo no se guarda.
 */
final class LogSentMail
{
    public function __construct(private readonly RecordMail $record) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $recipients = array_map(
            fn (Address $address): string => $address->getAddress(),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        );

        $class = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;

        $this->record->handle($recipients, (string) $message->getSubject(), $class === null ? 'Correo' : class_basename($class));
    }
}
