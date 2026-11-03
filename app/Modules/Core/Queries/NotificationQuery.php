<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
     * El total y las no leídas en una sola consulta, para la campana y la página; con `$classes`, solo las de esos
     * tipos de aviso.
     *
     * @param  list<string>  $classes
     * @return array{total: int, unread: int}
     */
    public function counts(User $user, array $classes = []): array
    {
        $query = $user->notifications()->reorder()->toBase();

        if ($classes !== []) {
            $query->whereIn('type', $classes);
        }

        $row = $query
            ->selectRaw('count(*) as total, count(*) filter (where read_at is null) as unread')
            ->first();

        return ['total' => (int) ($row->total ?? 0), 'unread' => (int) ($row->unread ?? 0)];
    }

    /**
     * @return Collection<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string, role: ?string}>
     */
    public function recent(User $user, int $limit = self::RECENT, bool $withCampus = false): Collection
    {
        $notifications = $this->newestFirst($user)->limit($limit)->get();
        $codes = $withCampus ? $this->campusCodes($notifications->all()) : [];
        $today = $this->today();

        return $notifications
            ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $codes, $today))
            ->values();
    }

    /**
     * Los tipos de aviso que tiene el usuario, para el filtro de la página, por etiqueta: la llave de la URL
     * (NexusNotification::typeKey) con su etiqueta en español y las clases que cubre. Se calcula una vez por pintado
     * y se pasa a `paginated()` y `counts()`.
     *
     * @return array<string, array{label: string, classes: list<string>}>
     */
    public function typeMap(User $user): array
    {
        $map = [];

        foreach ($user->notifications()->reorder()->toBase()->distinct()->pluck('type') as $class) {
            $key = $this->typeKey($class);
            $map[$key]['label'] = $this->typeLabel($class);
            $map[$key]['classes'][] = $class;
        }

        uasort($map, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $map;
    }

    /**
     * Una página de la lista completa de «Todas las notificaciones», de la más nueva a la más vieja; con
     * `$onlyUnread`, solo las que no ha leído; con `$classes`, solo las de esos tipos de aviso.
     *
     * @param  list<string>  $classes
     * @return LengthAwarePaginator<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string, role: ?string}>
     */
    public function paginated(User $user, bool $onlyUnread = false, int $perPage = self::PER_PAGE, array $classes = [], bool $withCampus = false): LengthAwarePaginator
    {
        $query = $this->newestFirst($user);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        if ($classes !== []) {
            $query->whereIn('type', $classes);
        }

        $today = $this->today();
        $page = $query->paginate($perPage);
        $codes = $withCampus ? $this->campusCodes($page->items()) : [];

        return $page->through(fn (DatabaseNotification $notification): array => $this->row($notification, $codes, $today));
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
     * @return array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, campus: ?string, role: ?string}
     */
    private function row(DatabaseNotification $notification, array $campusCodes, CarbonImmutable $today): array
    {
        $message = NotificationMessage::fromArray($notification->data);
        $at = CarbonImmutable::parse($notification->created_at);
        [$group, $groupLabel] = $this->day($at, $today);

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
            'role' => $message->role?->label(),
        ];
    }

    /**
     * El grupo del día en que llegó: Hoy, Ayer, Esta semana (la semana en curso) o Antes.
     *
     * @return array{0: string, 1: string}
     */
    private function day(CarbonImmutable $at, CarbonImmutable $today): array
    {
        $day = $at->timezone(config('app.timezone'))->startOfDay();

        return match (true) {
            $day->equalTo($today) || $day->greaterThan($today) => ['hoy', 'Hoy'],
            $day->equalTo($today->subDay()) => ['ayer', 'Ayer'],
            $day->greaterThanOrEqualTo($today->startOfWeek(CarbonInterface::MONDAY)) => ['semana', 'Esta semana'],
            default => ['antes', 'Antes'],
        };
    }

    /** El inicio del día de hoy en la zona de la aplicación, una vez por consulta. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
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
        return is_a($class, NexusNotification::class, true) ? $class::typeKey() : Str::kebab(str_replace('\\', '', $class));
    }

    private function typeLabel(string $class): string
    {
        return is_a($class, NexusNotification::class, true) ? $class::typeLabel() : Str::headline(class_basename($class));
    }
}
