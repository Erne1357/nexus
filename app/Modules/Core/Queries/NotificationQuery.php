<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la campana de la banda (tarea 2.9): cuántas notificaciones no ha leído el usuario y las más
 * recientes, de la más nueva a la más vieja. Solo las suyas: la consulta parte de su relación `notifications`.
 */
final class NotificationQuery
{
    /** Cuántas muestra la tarjeta de la campana. */
    public const int RECENT = 15;

    /** Cuántas lleva cada página de «Todas las notificaciones». */
    public const int PER_PAGE = 25;

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /** Cuántas notificaciones tiene el usuario en total, leídas o no. */
    public function total(User $user): int
    {
        return $user->notifications()->count();
    }

    /**
     * El total y las no leídas en una sola consulta, para la campana y la página.
     *
     * @return array{total: int, unread: int}
     */
    public function counts(User $user): array
    {
        $row = $user->notifications()->reorder()->toBase()
            ->selectRaw('count(*) as total, count(*) filter (where read_at is null) as unread')
            ->first();

        return ['total' => (int) ($row->total ?? 0), 'unread' => (int) ($row->unread ?? 0)];
    }

    /**
     * @return Collection<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable}>
     */
    public function recent(User $user, int $limit = self::RECENT): Collection
    {
        return $this->newestFirst($user)->limit($limit)->get()
            ->map(fn (DatabaseNotification $notification): array => $this->row($notification))
            ->values();
    }

    /**
     * Una página de la lista completa de «Todas las notificaciones», de la más nueva a la más vieja; con
     * `$onlyUnread`, solo las que no ha leído.
     *
     * @return LengthAwarePaginator<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable}>
     */
    public function paginated(User $user, bool $onlyUnread = false, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = $this->newestFirst($user);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage)->through(fn (DatabaseNotification $notification): array => $this->row($notification));
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function newestFirst(User $user): Builder
    {
        return $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable}
     */
    private function row(DatabaseNotification $notification): array
    {
        $message = NotificationMessage::fromArray($notification->data);

        return [
            'id' => $notification->id,
            'title' => $message->title,
            'body' => $message->body,
            'icon' => $message->icon,
            'tone' => $message->tone,
            'read' => $notification->read_at !== null,
            'at' => CarbonImmutable::parse($notification->created_at),
        ];
    }
}
