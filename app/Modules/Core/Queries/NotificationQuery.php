<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lo que muestra la campana de la banda (tarea 2.9): cuántas notificaciones no ha leído el usuario y las más
 * recientes, de la más nueva a la más vieja. Solo las suyas: la consulta parte de su relación `notifications`. El
 * puesto de cada aviso se nombra como en su credencial (RoleTitleQuery: el jefe de Servicios Escolares, por su unidad,
 * AZ-02), una consulta por puesto en cada lista.
 */
final class NotificationQuery
{
    public function __construct(
        private readonly Actor $context,
        private readonly RoleTitleQuery $titles,
    ) {}

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
     * @return Collection<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, role: ?string}>
     */
    public function recent(User $user, int $limit = self::RECENT): Collection
    {
        $notifications = $this->newestFirst($user)->limit($limit)->get();
        $today = $this->today();
        $roleTitle = $this->roleTitles($user);

        return $notifications
            ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $today, $roleTitle))
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
     * @return LengthAwarePaginator<int, array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, role: ?string}>
     */
    public function paginated(User $user, bool $onlyUnread = false, int $perPage = self::PER_PAGE, array $classes = []): LengthAwarePaginator
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
        $roleTitle = $this->roleTitles($user);

        return $page->through(fn (DatabaseNotification $notification): array => $this->row($notification, $today, $roleTitle));
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function newestFirst(User $user): Builder
    {
        return $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * El nombre de cada puesto de `$user` como en su credencial, calculado la primera vez que un aviso lo pide.
     *
     * @return Closure(Role): string
     */
    private function roleTitles(User $user): Closure
    {
        $titles = [];

        return function (Role $role) use ($user, &$titles): string {
            return $titles[$role->value] ??= $this->titles->of($this->context->forUser($user), $role)['title'];
        };
    }

    /**
     * @param  Closure(Role): string  $roleTitle
     * @return array{id: string, title: string, body: string, icon: string, tone: string, read: bool, at: CarbonImmutable, group: string, group_label: string, role: ?string}
     */
    private function row(DatabaseNotification $notification, CarbonImmutable $today, Closure $roleTitle): array
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
            'role' => $message->role === null ? null : $roleTitle($message->role),
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

    private function typeKey(string $class): string
    {
        return is_a($class, NexusNotification::class, true) ? $class::typeKey() : Str::kebab(str_replace('\\', '', $class));
    }

    private function typeLabel(string $class): string
    {
        return is_a($class, NexusNotification::class, true) ? $class::typeLabel() : Str::headline(class_basename($class));
    }
}
