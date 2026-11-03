<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\NotificationTarget;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Exceptions\UrlGenerationException;

/**
 * Abre una notificación desde la campana (tarea 2.9): la marca leída y da a dónde lleva. Si su destino ya no
 * está disponible para el usuario (lo decide su clase, NexusNotification::unavailableReason, o la ruta ya no
 * acepta sus parámetros), da el motivo en lugar de la dirección, para que la campana lo avise. Si el aviso es
 * de un periodo y un campus, deja la credencial en ellos (SetActiveContext), para que la pantalla muestre lo que
 * dice el aviso; si el usuario ya no trabaja en ese campus, tampoco lo lleva. Solo abre notificaciones del
 * usuario, que es el de la sesión: la credencial que cambia es la suya.
 *
 * Si el aviso es de un puesto (`data.role`; sin él o con uno que ya no existe, se abre con el perfil activo) que el usuario tiene y no es el activo, todo lo anterior se decide
 * con ese puesto (ScopedContext::asRole(), sin tocar la sesión) y, solo si se puede abrir, cambia a ese perfil
 * (SwitchRole) antes de mover la credencial, para que el destino se vea con su alcance; si no se puede, la sesión
 * se queda como estaba. Si ya no tiene el puesto, se decide con ese puesto igual (AZ-S8): sin roles, el aviso
 * no está disponible, aunque el perfil activo sea otro (el de Administración cubre todo).
 */
final class OpenNotification
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly SetActiveContext $setActiveContext,
        private readonly SwitchRole $switchRole,
    ) {}

    /**
     * @throws AuthorizationException si la notificación no es del usuario o él no es el de la sesión
     */
    public function handle(User $user, string $notificationId): NotificationTarget
    {
        if (! $this->context->user()?->is($user)) {
            throw new AuthorizationException('Solo puedes abrir tus notificaciones.');
        }

        $notification = KeyLookup::find($user->notifications()->getQuery(), $notificationId);

        if (! $notification instanceof DatabaseNotification) {
            throw new AuthorizationException('Esa notificación no es tuya.');
        }

        $notification->markAsRead();

        $message = NotificationMessage::fromArray($notification->data);
        $type = is_subclass_of($notification->type, NexusNotification::class) ? $notification->type : NexusNotification::class;
        $addressed = $message->role;
        $role = $this->roleToSwitchTo($addressed);
        $context = $addressed === null ? $this->context : $this->context->asRole($addressed);

        $problem = $type::unavailableReason($context, $message);

        if ($problem !== null) {
            return NotificationTarget::unavailable($problem);
        }

        try {
            $url = route($message->route, $message->parameters);
        } catch (UrlGenerationException) {
            return NotificationTarget::unavailable('Esa pantalla no está disponible.');
        }

        $campus = null;
        $term = null;

        if ($message->campusId !== null) {
            $campus = KeyLookup::find(Campus::query(), $message->campusId);

            if (! $campus instanceof Campus || ! $context->campuses()->contains($campus)) {
                return NotificationTarget::unavailable('Ya no trabajas en el campus de ese aviso.');
            }

            $term = $message->termId === null ? $this->context->activeTerm() : KeyLookup::find(Term::query(), $message->termId);

            if (! $term instanceof Term) {
                return NotificationTarget::unavailable('El periodo de ese aviso ya no existe.');
            }
        }

        if ($role !== null) {
            $this->switchRole->handle($role);
        }

        if ($campus !== null && $term !== null) {
            $this->moveCredential($campus, $term);
        }

        return NotificationTarget::to($url, switchedRole: $role !== null);
    }

    /**
     * El perfil al que hay que cambiar para abrir un aviso del puesto `$addressed` (null si no es de un puesto): ese
     * puesto si el usuario lo tiene y no es el activo; si no, ninguno.
     */
    private function roleToSwitchTo(?Role $addressed): ?Role
    {
        if ($addressed === null) {
            return null;
        }

        return $this->context->activeRole() !== $addressed && in_array($addressed, $this->context->heldRoles(), true) ? $addressed : null;
    }

    /**
     * Deja la credencial en el periodo y el campus del aviso. El periodo actual se deja como el de omisión, igual
     * que en el selector de la credencial. Si ya está ahí, no la toca (cambiarla olvida las últimas pantallas del
     * menú).
     */
    private function moveCredential(Campus $campus, Term $term): void
    {
        if (! $campus->is($this->context->activeCampus()) || ! $term->is($this->context->activeTerm())) {
            $this->setActiveContext->handle($campus, $term->is_current ? null : $term);
        }
    }
}
