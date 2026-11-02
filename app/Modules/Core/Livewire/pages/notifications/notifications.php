<?php

use App\Modules\Core\Livewire\Concerns\OpensNotifications;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Todas las notificaciones» (se llega desde «Ver todas» de la campana; no está en el menú): la lista completa
 * del usuario, de 25 en 25, con el filtro Todas / Sin leer (`?filtro=`), «Marcar todas como leídas» y abrir cada
 * una con OpenNotification, igual que la campana (OpensNotifications). Solo ve y abre las suyas: la consulta parte
 * de su relación.
 */
new #[Title('Notificaciones')] class extends Component
{
    use OpensNotifications, Toast, WithPagination;

    /** `todas` o `sin-leer`; va en la URL. */
    #[Url(as: 'filtro', except: 'todas')]
    public string $filter = 'todas';

    public function mount(): void
    {
        $this->normalizeFilter();
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilter();
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ScopedContext $context, NotificationQuery $notifications): array
    {
        $user = $context->user();
        $onlyUnread = $this->filter === 'sin-leer';

        if ($user === null) {
            return ['items' => new LengthAwarePaginator([], 0, NotificationQuery::PER_PAGE), 'unread' => 0, 'total' => 0, 'onlyUnread' => $onlyUnread];
        }

        $items = $notifications->paginated($user, $onlyUnread);

        // Al leer avisos con el filtro «Sin leer», la página en curso puede quedar después de la última.
        if ($items->currentPage() > max(1, $items->lastPage())) {
            $this->setPage($items->lastPage());
            $items = $notifications->paginated($user, $onlyUnread);
        }

        return ['items' => $items, ...$notifications->counts($user), 'onlyUnread' => $onlyUnread];
    }

    /**
     * Un `?filtro=` que no es `todas` ni `sin-leer` (por ejemplo, tecleado a mano) vuelve al de omisión.
     */
    private function normalizeFilter(): void
    {
        if (! in_array($this->filter, ['todas', 'sin-leer'], true)) {
            $this->filter = 'todas';
        }
    }
};
