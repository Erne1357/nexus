<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Actions\RecordMail;
use App\Modules\Core\Enums\MailStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Anota en el registro de correos (CF-S8) el correo que no pudo salir. Laravel no avisa de un envío por correo que lanza
 * (MailChannel no dispara NotificationFailed), pero sí cuando un trabajo de la cola agota sus intentos (JobFailed): este
 * listener atiende (por `commandName`, porque `displayName` es la notificación) los de SendQueuedNotifications con el canal `mail` y los de SendQueuedMailable, y saca el destinatario
 * y el tipo del comando serializado, que lee con el mismo CallQueuedHandler de Laravel (también si está cifrado). Un correo que se manda sin cola y falla lanza en quien lo manda; ese no queda
 * registrado (limitación documentada en CF-S8). Nunca rompe lo que ya falló: si no puede leer el comando o anotar, lo
 * reporta y sigue.
 */
final class LogFailedQueuedMail
{
    public function __construct(
        private readonly RecordMail $record,
        private readonly Dispatcher $bus,
        private readonly Container $container,
    ) {}

    public function handle(JobFailed $event): void
    {
        $name = $event->job->payload()['data']['commandName'] ?? null;

        if (! in_array($name, [SendQueuedNotifications::class, SendQueuedMailable::class], true)) {
            return;
        }

        try {
            $command = $this->command($event);
            [$recipients, $subject, $kind] = $command instanceof SendQueuedNotifications
                ? $this->fromNotification($command)
                : $this->fromMailable($command);

            if ($kind === null) {
                return;
            }

            $this->record->handle($recipients, $subject, $kind, MailStatus::Failed, $event->exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function command(JobFailed $event): object
    {
        $reader = new class($this->bus, $this->container) extends CallQueuedHandler
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function read(array $data): object
            {
                return $this->getCommand($data);
            }
        };

        return $reader->read($event->job->payload()['data']);
    }

    /**
     * @return array{0: list<string>, 1: string, 2: ?string}
     */
    private function fromNotification(SendQueuedNotifications $command): array
    {
        $recipients = [];
        $mailed = false;

        foreach ($command->notifiables as $notifiable) {
            $channels = $command->channels ?? $command->notification->via($notifiable);

            if (! in_array('mail', (array) $channels, true)) {
                continue;
            }

            $mailed = true;
            $route = $notifiable instanceof AnonymousNotifiable ? $notifiable->routeNotificationFor('mail') : $notifiable->routeNotificationFor('mail', $command->notification);

            foreach ((array) $route as $key => $value) {
                $recipients[] = (string) (is_string($key) ? $key : $value);
            }
        }

        return [$recipients, $this->subject($command), $mailed ? class_basename($command->notification) : null];
    }

    /**
     * @return array{0: list<string>, 1: string, 2: string}
     */
    private function fromMailable(object $command): array
    {
        $mailable = $command->mailable;

        return [
            array_map(fn (array $to): string => (string) $to['address'], $mailable->to),
            (string) $mailable->subject,
            class_basename($mailable),
        ];
    }

    private function subject(SendQueuedNotifications $command): string
    {
        try {
            return (string) $command->notification->toMail($command->notifiables->first())->subject;
        } catch (Throwable) {
            return '';
        }
    }
}
