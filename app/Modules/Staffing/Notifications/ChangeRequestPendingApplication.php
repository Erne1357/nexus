<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use Illuminate\Support\Facades\Route;

/**
 * Llega a quien debe aplicar un cambio aprobado: el coordinador de la carrera dueña (horario) o el jefe del departamento (salón). Un cambio de horario lleva al editor de horarios del grupo cuando existe; si no, a la pantalla de solicitudes.
 */
final class ChangeRequestPendingApplication extends ChangeRequestNotification
{
    public static function typeKey(): string
    {
        return 'change-request-pending-application';
    }

    public static function typeLabel(): string
    {
        return 'Cambio por aplicar';
    }

    protected function content(): array
    {
        return ['Hay un cambio aprobado por aplicar', 'o-wrench-screwdriver', 'info'];
    }

    protected function route(): string
    {
        return $this->request->kind === ChangeRequestKind::Schedule && Route::has('scheduling.editor')
            ? 'scheduling.editor'
            : parent::route();
    }

    public function message(object $notifiable): NotificationMessage
    {
        $message = parent::message($notifiable);

        if ($message->route !== 'scheduling.editor') {
            return $message;
        }

        return new NotificationMessage($message->title, $message->body, $message->icon, $message->route, ['grupo' => (string) $this->request->group_id], $message->termId, $message->campusId, $message->tone);
    }
}
