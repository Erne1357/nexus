<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * @return Collection<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string}>
     */
    public function recent(User $user, int $limit = self::RECENT, bool $withCampus = false): Collection
    {
        $notifications = $this->newestFirst($user)->limit($limit)->get();
        $codes = $withCampus ? $this->campusCodes($notifications->all()) : [];

        return $notifications
            ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $codes))
            ->values();
    }

    /**
     * Los tipos de aviso que tiene el usuario, para el filtro de la página: la llave de la URL (`proposal-returned`)
     * con su etiqueta en español (NexusNotification::typeLabel).
     *
     * @return array<string, string>
     */
    public function types(User $user): array
    {
        $types = [];

        foreach ($user->notifications()->reorder()->toBase()->distinct()->pluck('type') as $class) {
            $types[$this->typeKey($class)] = $this->typeLabel($class);
        }

        asort($types);

        return $types;
    }

    /**
     * Una página de la lista completa de «Todas las notificaciones», de la más nueva a la más vieja; con
     * `$onlyUnread`, solo las que no ha leído.
     *
     * @return LengthAwarePaginator<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string}>
     */
    public function paginated(User $user, bool $onlyUnread = false, int $perPage = self::PER_PAGE, string $type = '', bool $withCampus = false): LengthAwarePaginator
    {
        $query = $this->newestFirst($user);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        if ($type !== '') {
            $query->whereIn('type', array_values(array_filter(
                $user->notifications()->reorder()->toBase()->distinct()->pluck('type')->all(),
                fn (string $class): bool => $this->typeKey($class) === $type,
            )));
        }

        $page = $query->paginate($perPage);
        $codes = $withCampus ? $this->campusCodes($page->items()) : [];

        return $page->through(fn (DatabaseNotification $notification): array => $this->row($notification, $codes));
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function newestFirst(User $user): Builder
    {
        return $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  array<string, string>  $campusCodes
     * @return array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string}
     */
    private function row(DatabaseNotification $notification, array $campusCodes = []): array
    {
        $message = NotificationMessage::fromArray($notification->data);
        $at = CarbonImmutable::parse($notification->created_at);
        [$group, $groupLabel] = $this->day($at);

        return [
            'id' => $notification->id,
            'title' => $message->title,
            'body' => $message->body,
            'icon' => $message->icon,
            'tone' => $message->tone,
            'read' => $notification->read_at !== null,
            'at' => $at,
            'group' => $group,
            'group_label' => $groupLabel,
            'campus' => $message->campusId === null ? null : ($campusCodes[$message->campusId] ?? null),
        ];
    }

    /**
     * El grupo del día en que llegó: Hoy, Ayer, Esta semana (la semana en curso) o Antes.
     *
     * @return array{0: string, 1: string}
     */
    private function day(CarbonImmutable $at): array
    {
        $day = $at->timezone(config('app.timezone'))->startOfDay();
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        return match (true) {
            $day->equalTo($today) || $day->greaterThan($today) => ['hoy', 'Hoy'],
            $day->equalTo($today->subDay()) => ['ayer', 'Ayer'],
            $day->greaterThanOrEqualTo($today->startOfWeek()) => ['semana', 'Esta semana'],
            default => ['antes', 'Antes'],
        };
    }

    /**
     * Las claves cortas (`C2`) de los campus que citan estos avisos, en una sola consulta.
     *
     * @param  array<int, DatabaseNotification>  $notifications
     * @return array<string, string>
     */
    private function campusCodes(array $notifications): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (DatabaseNotification $notification): ?string => NotificationMessage::fromArray($notification->data)->campusId,
            $notifications,
        ))));

        return $ids === [] ? [] : Campus::withTrashed()->whereKey($ids)->pluck('code', 'id')->all();
    }

    private function typeKey(string $class): string
    {
        return Str::kebab(class_basename($class));
    }

    private function typeLabel(string $class): string
    {
        return is_a($class, NexusNotification::class, true) ? $class::typeLabel() : Str::headline(class_basename($class));
    }
}
