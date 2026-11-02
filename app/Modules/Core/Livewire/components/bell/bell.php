<?php

use App\Modules\Core\Livewire\Concerns\OpensNotifications;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La campana de la banda (tarea 2.9; opción A de la maqueta, elegida el 2 de noviembre de 2026): el botón
 * con las no leídas y, al abrirlo, una tarjeta flotante con la misma forma que la de la credencial con las
 * notificaciones recientes del usuario (NotificationQuery), «Marcar todas como leídas» y, al pie, «Ver todas (N)», que lleva a core.notifications. Abrir una la marca
 * leída y lleva a su pantalla (OpenNotification); si ya no está disponible, lo avisa con un toast y se queda.
 * La banda la carga en diferido (lazy). Se refresca cada minuto mientras está a la vista, con dos consultas (el
 * total y las no leídas juntos, y las 15 recientes) para que la tarjeta abra sin esperar al servidor, y en
 * cuanto otra parte de la pantalla cambia las notificaciones (`notifications-changed`).
 */
new class extends Component
{
    use OpensNotifications, Toast;

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
        ['total' => $total, 'unread' => $unread] = $user === null ? ['total' => 0, 'unread' => 0] : $notifications->counts($user);

        return [
            'unread' => $unread,
            'badge' => $unread > 99 ? '99+' : (string) $unread,
            'total' => $total,
            'items' => $user === null ? collect() : $notifications->recent($user),
            'label' => match (true) {
                $unread === 0 => 'Notificaciones: sin notificaciones nuevas',
                $unread === 1 => 'Notificaciones: 1 sin leer',
                $unread > 99 => 'Notificaciones: más de 99 sin leer',
                default => "Notificaciones: {$unread} sin leer",
            },
        ];
    }
};
