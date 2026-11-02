<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\NotificationTarget;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Abre una notificación desde la campana (tarea 2.9): la marca leída y da a dónde lleva. Si su destino ya no
 * está disponible para el usuario (lo decide su clase, NexusNotification::unavailableReason), da el motivo
 * en lugar de la dirección, para que la campana lo avise. Si el aviso es de un periodo y un campus, deja la
 * credencial en ellos (SetActiveContext), para que la pantalla muestre lo que dice el aviso; si el usuario ya
 * no trabaja en ese campus, tampoco lo lleva. Solo abre notificaciones del usuario.
 */
final class OpenNotification
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly SetActiveContext $setActiveContext,
    ) {}

    /**
     * @throws AuthorizationException si la notificación no es del usuario
     */
    public function handle(User $user, string $notificationId): NotificationTarget
    {
        $notification = Str::isUuid($notificationId) ? $user->notifications()->whereKey($notificationId)->first() : null;

        if (! $notification instanceof DatabaseNotification) {
            throw new AuthorizationException('Esa notificación no es tuya.');
        }

        $notification->markAsRead();

        $message = NotificationMessage::fromArray($notification->data);
        $type = is_subclass_of($notification->type, NexusNotification::class) ? $notification->type : NexusNotification::class;
        $problem = $type::unavailableReason($user, $message) ?? $this->switchContext($message);

        return $problem === null
            ? NotificationTarget::to(route($message->route, $message->parameters))
            : NotificationTarget::unavailable($problem);
    }

    /**
     * Deja la credencial en el periodo y el campus del aviso, o da por qué no se puede. El periodo actual se
     * deja como el de omisión, igual que en el selector de la credencial.
     */
    private function switchContext(NotificationMessage $message): ?string
    {
        if ($message->campusId === null) {
            return null;
        }

        $campus = Campus::query()->find($message->campusId);

        if ($campus === null || ! $this->context->campuses()->contains($campus)) {
            return 'Ya no trabajas en el campus de ese aviso.';
        }

        $term = $message->termId === null ? $this->context->activeTerm() : Term::query()->find($message->termId);

        if ($term === null) {
            return 'El periodo de ese aviso ya no existe.';
        }

        $this->setActiveContext->handle($campus, $term->is_current ? null : $term);

        return null;
    }
}
