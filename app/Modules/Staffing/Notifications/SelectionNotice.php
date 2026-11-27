<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\CatalogValidation\Queries\HeadMailAddressQuery;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\RoutesMailAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Base de los avisos de la orden de selección (tarea 1.33, diseño 6.9, SEL-09): van por la campana y por el correo
 * institucional, con la regla de ChangeAlternativeNotice (RA-06 y CV11, HeadMailAddressQuery: el institucional de su
 * ficha de docente o, si no tiene, el de su usuario si es institucional; nunca el personal, y a una cuenta dada de baja
 * no le llega correo). Sin dirección, solo la campana. Los avisos al docente de la cola (SelectionTeacherNotice) caen
 * además a su correo personal si no tiene institucional (SEL-15); el de pendientes al jefe y al JPD, no.
 *
 * Salen de la cola solo después del commit (ShouldQueueAfterCommit): el reloj (AdvanceSelections) y la cancelación avisan
 * dentro de su transacción, y si se revierte no sale nada. La campana se guarda al confirmar (conexión `sync`); el correo
 * va por la cola (Microsoft Graph, unos 25 por minuto). No son avisos de la carga (AcademicLoadNotice): llevan a
 * «Oferta», que siempre se ve, así que el correo sale aunque el admin tenga oculta la carga académica.
 */
abstract class SelectionNotice extends NexusNotification implements RoutesMailAddress, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return $notifiable instanceof User && $this->mailAddressFor($notifiable) !== null ? ['database', 'mail'] : ['database'];
    }

    public function mailAddressFor(User $user): ?string
    {
        return $user->is_active ? app(HeadMailAddressQuery::class)->for($user) : null;
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * El saludo del correo, con el nombre si es un usuario.
     */
    protected static function greetingFor(object $notifiable): string
    {
        return $notifiable instanceof User ? "Hola, {$notifiable->name}" : 'Hola';
    }
}
