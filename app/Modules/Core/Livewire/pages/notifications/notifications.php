<?php

use App\Modules\Core\Actions\MarkAllNotificationsRead;
use App\Modules\Core\Actions\OpenNotification;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Todas las notificaciones» (se llega desde «Ver todas» de la campana; no está en el menú): la lista completa
 * del usuario, de 25 en 25, con el filtro Todas / Sin leer (`?filtro=`), «Marcar todas como leídas» y abrir cada
 * una con OpenNotification, igual que la campana. Solo ve y abre las suyas: la consulta parte de su relación.
 */
new #[Title('Notificaciones')] class extends Component
{
    use Toast, WithPagination;

    /** `todas` o `sin-leer`; va en la URL. */
    #[Url(as: 'filtro', except: 'todas')]
    public string $filter = 'todas';

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['todas', 'sin-leer'], true)) {
            $this->filter = 'todas';
        }

        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ScopedContext $context, NotificationQuery $notifications): array
    {
        $user = $context->user();
        $onlyUnread = $this->filter === 'sin-leer';

        return [
            'items' => $user === null
                ? new LengthAwarePaginator([], 0, NotificationQuery::PER_PAGE)
                : $notifications->paginated($user, $onlyUnread),
            'unread' => $user === null ? 0 : $notifications->unreadCount($user),
            'total' => $user === null ? 0 : $notifications->total($user),
            'onlyUnread' => $onlyUnread,
        ];
    }

    public function open(string $id, OpenNotification $openNotification, ScopedContext $context): mixed
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
            $this->warning($target->problem ?? 'Esa pantalla no está disponible.');

            return null;
        }

        return $this->redirect($target->url, navigate: true);
    }

    public function markAllRead(MarkAllNotificationsRead $markAllNotificationsRead, ScopedContext $context): void
    {
        $user = $context->user();

        if ($user !== null) {
            $markAllNotificationsRead->handle($user);
        }
    }
};
