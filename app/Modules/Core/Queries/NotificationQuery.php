<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
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

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable}>
     */
    public function recent(User $user, int $limit = self::RECENT): Collection
    {
        return $user->notifications()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
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
            })
            ->values();
    }
}
