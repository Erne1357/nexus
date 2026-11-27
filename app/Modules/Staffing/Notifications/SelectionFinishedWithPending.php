<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega al jefe del departamento y al JPD cuando termina la ronda extra con docentes sin cerrar (diseño 6.3 y 6.9;
 * decisión 8: lo que siga sin resolver lo asignan a mano), por la campana y por correo, con la lista de pendientes. Lleva
 * al horario de esa selección terminada (`staffing.offer?seleccion=<id>`), que abren con `staffing.selection.view`
 * aunque no sean docentes y aunque el admin tenga oculta la carga académica; ahí asignan o quitan docentes.
 *
 * CE-02 (confirmado el 26 de noviembre de 2026): compete al puesto (PositionNotice): su correo va al buzón del
 * nombramiento por el que lo recibe el jefe o el JPD y, sin buzón, espera; la campana llega igual.
 */
final class SelectionFinishedWithPending extends SelectionNotice implements PositionNotice
{
    /**
     * @param  list<string>  $teachers
     */
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly string $selectionId,
        private readonly array $teachers,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-finished-with-pending';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->positionChannels() ?? parent::via($notifiable);
    }

    /**
     * CE-02: el puesto de quien lo recibe, con el alcance de su nombramiento.
     */
    public function position(): ?PositionRef
    {
        return $this->holderPosition();
    }

    public static function typeLabel(): string
    {
        return 'Selección terminada con pendientes';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Terminó la selección de '.$this->department,
            body: count($this->teachers).' sin resolver: '.implode(', ', $this->teachers).'.',
            icon: 'o-exclamation-triangle',
            route: 'staffing.offer',
            parameters: ['seleccion' => $this->selectionId],
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->teachers);
        $mail = (new MailMessage)
            ->subject("Terminó la selección de {$this->department}: {$count} sin resolver")
            ->greeting(self::greetingFor($notifiable))
            ->line("Terminó la orden de selección de carga de {$this->department}, con la ronda extra. ".($count === 1 ? 'Este docente no completó su carga:' : "Estos {$count} docentes no completaron su carga:"));

        foreach ($this->teachers as $teacher) {
            $mail->line("- {$teacher}");
        }

        return $mail
            ->line('Asígnales sus grupos a mano desde el horario de la selección: toca un grupo y usa «Asignar a…».')
            ->action('Abrir el horario de la selección', route('staffing.offer', ['seleccion' => $this->selectionId]));
    }
}
