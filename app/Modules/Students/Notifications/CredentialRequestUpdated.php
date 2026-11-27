<?php

namespace App\Modules\Students\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\RoutesMailAddress;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Rules\CredentialRequestRules;
use App\Modules\Students\Rules\InstitutionalEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CO-09 (C6): le avisa al alumno, por la campana y por su correo, que su solicitud de credencial se citó, se reprogramó,
 * quedó lista, se entregó o se rechazó. Cancelar no avisa. Lleva a «Mis datos» › «Credencial». El correo va a
 * `users.email` si es institucional o, si no, al institucional que sale de su número de control (AC-13); nunca al
 * personal (CE-01). La campana se guarda en el
 * momento (conexión `sync`) y el correo sale de la cola, después del commit.
 */
final class CredentialRequestUpdated extends NexusNotification implements RoutesMailAddress, ShouldQueue
{
    use Queueable;

    public const string SCHEDULED = 'scheduled';

    public const string RESCHEDULED = 'rescheduled';

    public const string READY = 'ready';

    public const string DELIVERED = 'delivered';

    public const string REJECTED = 'rejected';

    /** El texto del aviso de cada cambio. */
    private const array TITLES = [
        self::SCHEDULED => 'Te citaron por tu solicitud de credencial',
        self::RESCHEDULED => 'Cambió la cita de tu solicitud de credencial',
        self::READY => 'Tu credencial está lista para recoger',
        self::DELIVERED => 'Tu solicitud de credencial se entregó',
        self::REJECTED => 'Rechazaron tu solicitud de credencial',
    ];

    public static function typeKey(): string
    {
        return 'credential-request-updated';
    }

    public static function typeLabel(): string
    {
        return 'Solicitudes de credencial';
    }

    public function __construct(private readonly CredentialRequest $request, private readonly string $change)
    {
        $this->afterCommit();
    }

    /**
     * Avisa al usuario del alumno de la solicitud; sin usuario no hay a quién.
     */
    public static function send(CredentialRequest $request, string $change): void
    {
        $user = $request->student()->first()?->user;

        $user?->notify(new self($request, $change));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $this->mailAddressFor($notifiable) !== null ? ['database', 'mail'] : ['database'];
    }

    public function mailAddressFor(User $user): ?string
    {
        if (InstitutionalMail::isInstitutional($user->email)) {
            return mb_strtolower(trim((string) $user->email));
        }

        $controlNumber = $this->request->student()->first()?->control_number;

        return $controlNumber === null ? null : InstitutionalEmail::of($controlNumber);
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: self::TITLES[$this->change],
            body: $this->detail(),
            icon: 'o-identification',
            route: 'students.my-record',
            parameters: ['tab' => 'credencial'],
            tone: $this->change === self::REJECTED ? 'warning' : 'info',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::TITLES[$this->change])
            ->greeting("Hola, {$notifiable->name}")
            ->line($this->detail())
            ->action('Ver mi credencial', route('students.my-record', ['tab' => 'credencial']));
    }

    /**
     * Lo que cambió, en una línea: la cita con su fecha, hora y lugar, el motivo del rechazo, o el tipo de solicitud.
     */
    private function detail(): string
    {
        return match ($this->change) {
            self::SCHEDULED, self::RESCHEDULED => 'Tu cita es el '
                .$this->request->scheduled_at?->setTimezone(CredentialRequestRules::TIMEZONE)->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] H:mm')
                ." en {$this->request->place}.",
            self::REJECTED => "Motivo: {$this->request->rejection_reason}",
            self::READY => 'Pasa a recogerla con tu credencial anterior o una identificación.',
            default => $this->kind().'.',
        };
    }

    private function kind(): string
    {
        return match (true) {
            $this->request->wants_reposition && $this->request->wants_photo => 'Reposición de credencial y foto',
            $this->request->wants_reposition => 'Reposición de credencial',
            default => 'Foto de credencial',
        };
    }
}
