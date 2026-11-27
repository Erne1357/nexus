<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\OpensNotifications;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\NotificationQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Todas las notificaciones» (se llega desde «Ver todas» de la campana; no está en el menú): la lista completa
 * del usuario, de 25 en 25, con el filtro Todas / Sin leer (`?filtro=`) y por tipo de aviso (`?tipo=`), agrupada por día, «Marcar todas como leídas» y abrir cada
 * una con OpenNotification, igual que la campana (OpensNotifications). Solo ve y abre las suyas: la consulta parte
 * de su relación.
 */
new #[Title('Notificaciones')] class extends Component
{
    use OpensNotifications, Toast, WithPagination;

    /** `todas` o `sin-leer`; va en la URL. */
    #[Url(as: 'filtro', except: 'todas')]
    public string $filter = 'todas';

    /** La llave del tipo de aviso (`proposal-returned`), o vacío para todos; va en la URL. */
    #[Url(as: 'tipo', except: '')]
    public string $type = '';

    public function mount(): void
    {
        $this->normalizeFilter();
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilter();
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, NotificationQuery $notifications): array
    {
        $user = $context->user();
        $onlyUnread = $this->filter === 'sin-leer';

        if ($user === null) {
            return ['items' => new LengthAwarePaginator([], 0, NotificationQuery::PER_PAGE), 'unread' => 0, 'total' => 0, 'onlyUnread' => $onlyUnread, 'types' => [], 'showRole' => false];
        }

        // Los tipos se calculan una vez por pintado. Un `?tipo=` que ya no es de ninguno de sus avisos (o que
        // se tecleó a mano) vuelve a todos.
        $typeMap = $notifications->typeMap($user);

        if ($this->type !== '' && ! array_key_exists($this->type, $typeMap)) {
            $this->type = '';
        }

        $classes = $this->type === '' ? [] : $typeMap[$this->type]['classes'];
        $items = $notifications->paginated($user, $onlyUnread, classes: $classes);

        // Al leer avisos con el filtro «Sin leer», la página en curso puede quedar después de la última.
        if ($items->currentPage() > max(1, $items->lastPage())) {
            $this->setPage($items->lastPage());
            $items = $notifications->paginated($user, $onlyUnread, classes: $classes);
        }

        return [
            'items' => $items,
            ...$notifications->counts($user, $classes),
            'onlyUnread' => $onlyUnread,
            'types' => collect($typeMap)->map(fn (array $type, string $key): array => ['id' => $key, 'name' => $type['label']])->values()->all(),
            'showRole' => count($context->heldRoles()) > 1,
        ];
    }

    /**
     * Con un tipo elegido, «Marcar todas como leídas» marca solo ese tipo.
     *
     * @return list<string>
     */
    protected function classesToMarkRead(User $user): array
    {
        return $this->type === '' ? [] : (app(NotificationQuery::class)->typeMap($user)[$this->type]['classes'] ?? []);
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
