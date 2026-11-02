<?php

use App\Modules\Core\Actions\MarkAllNotificationsRead;
use App\Modules\Core\Actions\OpenNotification;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La campana de la banda (tarea 2.9; opción A de la maqueta, elegida el 2 de noviembre de 2026): el botón
 * con las no leídas y, al abrirlo, una tarjeta flotante con la misma forma que la de la credencial con las
 * notificaciones recientes del usuario (NotificationQuery) y «Marcar todas como leídas». Abrir una la marca
 * leída y lleva a su pantalla (OpenNotification); si ya no está disponible, lo avisa con un toast y se queda.
 * La banda la carga en diferido (lazy). Se refresca cada minuto mientras está a la vista: un conteo y las 15
 * recientes, para que la tarjeta abra sin esperar al servidor. Abrir y cerrar es de Alpine; cerrar la credencial al
 * abrirla usa el componente nexusShell de <body>.
 */
new class extends Component
{
    use Toast;

    /**
     * Lo que se ve mientras llega la campana (la banda la carga en diferido, para no sumar sus consultas a las
     * de cada pantalla): el mismo botón, sin globo ni acción.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="flex-none in-data-[menu=open]:invisible">
                <span data-testid="bell-placeholder" aria-hidden="true" class="grid size-11 place-items-center rounded-lg border border-band-content/20 text-band-content/70">
                    <x-icon name="o-bell" class="w-[22px] h-[22px]" />
                </span>
            </div>
            HTML;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ScopedContext $context, NotificationQuery $notifications): array
    {
        $user = $context->user();
        $unread = $user === null ? 0 : $notifications->unreadCount($user);

        return [
            'unread' => $unread,
            'badge' => $unread > 99 ? '99+' : (string) $unread,
            'items' => $user === null ? collect() : $notifications->recent($user),
            'label' => match (true) {
                $unread === 0 => 'Notificaciones: sin notificaciones nuevas',
                $unread === 1 => 'Notificaciones: 1 sin leer',
                $unread > 99 => 'Notificaciones: más de 99 sin leer',
                default => "Notificaciones: {$unread} sin leer",
            },
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
