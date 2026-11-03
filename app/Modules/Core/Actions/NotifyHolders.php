<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manda un aviso a cada destinatario de RecipientsQuery::holders(), con el puesto por el que le toca (`data.role`):
 * una copia de la notificación por persona. Da los usuarios avisados, en el orden recibido.
 */
final class NotifyHolders
{
    /**
     * @param  list<array{user: User, role: Role}>  $holders
     * @return Collection<int, User>
     */
    public function handle(array $holders, NexusNotification $notification): Collection
    {
        $notified = new Collection;

        foreach ($holders as $holder) {
            $holder['user']->notify((clone $notification)->addressedTo($holder['role']));
            $notified->push($holder['user']);
        }

        return $notified;
    }
}
