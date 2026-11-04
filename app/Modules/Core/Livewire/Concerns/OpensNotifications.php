<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Actions\MarkAllNotificationsRead;
use App\Modules\Core\Actions\OpenNotification;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\On;

/**
 * Abrir una notificación y marcarlas todas como leídas, igual en la campana y en «Todas las notificaciones»:
 * OpenNotification la marca leída y lleva a su pantalla o, si ya no está disponible, se avisa con un toast y no
 * se lleva. Cada cambio avisa con `notifications-changed` para que el otro componente de la misma pantalla (la
 * campana en la banda y la página) se vuelva a pintar, salvo si abrir el aviso cambió el perfil: entonces la
 * pantalla del aviso se carga completa, sin wire:navigate. Pide el trait Toast de MaryUI.
 */
trait OpensNotifications
{
    public function open(string $id, OpenNotification $openNotification, Actor $context): mixed
    {
        $user = $context->user();

        if ($user === null) {
            return null;
        }

        try {
            $target = $openNotification->handle($user, $id);
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return null;
        }

        if ($target->url === null) {
            $this->dispatch('notifications-changed');
            $this->warning($target->problem ?? 'Esa pantalla no está disponible.');

            return null;
        }

        // Con otro perfil, los componentes de esta página ya no pueden actuar (ActiveRoleHook): no se les avisa y la
        // pantalla del aviso se carga completa, pintada con el perfil nuevo.
        if ($target->switchedRole) {
            return $this->redirect($target->url);
        }

        $this->dispatch('notifications-changed');

        return $this->redirect($target->url, navigate: true);
    }

    public function markAllRead(MarkAllNotificationsRead $markAllNotificationsRead, Actor $context): void
    {
        $user = $context->user();

        if ($user !== null) {
            $markAllNotificationsRead->handle($user, $this->classesToMarkRead($user));
            $this->dispatch('notifications-changed');
        }
    }

    /**
     * Los tipos de aviso que marca «Marcar todas como leídas»: vacío, todos (la campana). La página lo acota al
     * tipo que filtra.
     *
     * @return list<string>
     */
    protected function classesToMarkRead(User $user): array
    {
        return [];
    }

    /**
     * Otro componente cambió las notificaciones del usuario: se vuelve a pintar con lo nuevo.
     */
    #[On('notifications-changed')]
    public function refreshNotifications(): void {}
}
