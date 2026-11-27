<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\CatalogValidation\Queries\HeadMailAddressQuery;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\RoutesMailAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Base de los avisos de una alternativa del motor (tarea 8.7, decisiones del usuario del 18 de noviembre de 2026): van
 * por la campana y por el correo institucional, con la regla de RA-06 y CV11 (HeadMailAddressQuery: el institucional
 * de su ficha de docente o, si no tiene, el de su usuario si es institucional; nunca el personal, y a una cuenta dada
 * de baja no le llega correo). Sin dirección, solo la campana; a quien no tiene usuario (un docente sin acceso), solo
 * el correo (`Notification::route('mail', …)`). Salen de la cola después del commit; la campana se guarda en el
 * momento (conexión `sync`). Cada aviso decide si es de la carga (AcademicLoadNotice, MN-05): el del docente lleva a «Mi carga», que
 * siempre se ve, y no lo es.
 */
abstract class ChangeAlternativeNotice extends NexusNotification implements RoutesMailAddress, ShouldQueue
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
}
