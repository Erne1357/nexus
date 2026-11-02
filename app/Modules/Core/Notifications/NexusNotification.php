<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Base de las notificaciones de Nexus (tarea 2.9): van por el canal `database` y guardan un
 * NotificationMessage, que la campana de la banda lee sin conocer la clase. Cada módulo define las suyas en
 * `<Modulo>/Notifications` (el correo queda para después).
 *
 * Al abrirla, la campana pregunta a la clase si su destino sigue disponible para el usuario
 * (unavailableReason) y, si no, se lo dice en lugar de llevarlo: cada clase agrega sus condiciones (que el
 * registro exista, que siga en su alcance) a la de omisión, que la ruta exista.
 *
 * Laravel clona la notificación para cada destinatario y canal antes de darle llave; __clone le da a cada
 * copia un UUIDv7 propio (ADR-0014), en lugar del UUIDv4 que pondría Laravel.
 */
abstract class NexusNotification extends Notification
{
    abstract public function message(object $notifiable): NotificationMessage;

    /**
     * El nombre del tipo de aviso en español, para el filtro de «Todas las notificaciones».
     */
    public static function typeLabel(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /**
     * La llave del tipo en la URL del filtro (`?tipo=`). Por omisión sale del nombre completo de la clase, así que
     * dos clases con el mismo nombre corto en módulos distintos no chocan; una clase puede declarar una más corta.
     */
    public static function typeKey(): string
    {
        return Str::kebab(str_replace('\\', '', static::class));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->message($notifiable)->toArray();
    }

    /**
     * Por qué el destino ya no se puede abrir para `$user`, o null si se puede. Recibe el mensaje guardado,
     * no la notificación, porque la campana solo tiene la fila de la base.
     */
    public static function unavailableReason(User $user, NotificationMessage $message): ?string
    {
        if ($message->route === null || ! Route::has($message->route)) {
            return 'Esa pantalla no está disponible.';
        }

        return null;
    }

    public function __clone(): void
    {
        $this->id = (string) Str::uuid7();
    }
}
