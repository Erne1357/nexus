<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega a cada docente de la cola cuando el admin cancela una selección ya publicada, antes de su primera hora (SEL-11,
 * diseño 6.3), por la campana y por correo: su turno ya no corre. Lleva a «Oferta», que vuelve a mostrar la oferta.
 */
final class SelectionCancelled extends SelectionNotice
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly CarbonImmutable $startsAt,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-cancelled';
    }

    public static function typeLabel(): string
    {
        return 'Selección cancelada';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Se canceló la selección de '.$this->department,
            body: 'Tu turno ya no corre. Si se vuelve a publicar, te llega tu nueva hora.',
            icon: 'o-x-circle',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = $this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->locale('es');

        return (new MailMessage)
            ->subject('Se canceló la selección de carga de '.$this->department)
            ->greeting(self::greetingFor($notifiable))
            ->line("Se canceló la orden de selección de carga de {$this->department}, que iba a empezar el ".$start->translatedFormat('l j \d\e F').' a las '.$start->format('G:i').'.')
            ->line('Tu turno ya no corre. Si se vuelve a publicar, te llega por aquí tu nueva hora.')
            ->action('Abrir Oferta', route('staffing.offer'));
    }
}
