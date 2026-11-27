<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\PositionNotice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manda un aviso a cada destinatario de RecipientsQuery::holders(), con el puesto por el que le toca (`data.role`):
 * una copia de la notificación por persona. Da los usuarios avisados, en el orden recibido.
 *
 * CE-02: cada copia lleva, con el puesto, el alcance del nombramiento por el que esa persona lo recibe (`scope`), del que
 * sale su puesto. Si es un aviso de puesto (PositionNotice), solo la primera copia de cada puesto (mismo rol y alcance)
 * lleva el correo; las demás de ese mismo puesto van solo a la campana (sharesPositionMail()), para que el buzón no
 * reciba el mismo correo una vez por cada persona con ese puesto (supuesto por confirmar). Dos puestos distintos que
 * resuelven al mismo buzón (p. ej. dos coordinaciones de campus con un buzón de todos los campus) le mandan un correo
 * cada uno.
 */
final class NotifyHolders
{
    /**
     * @param  list<array{user: User, role: Role, scope?: AssignmentScope}>  $holders
     * @return Collection<int, User>
     */
    public function handle(array $holders, NexusNotification $notification): Collection
    {
        $notified = new Collection;
        $mailed = [];

        foreach ($holders as $holder) {
            $copy = (clone $notification)->addressedTo($holder['role'], $holder['scope'] ?? null);
            $position = $copy instanceof PositionNotice ? $copy->position() : null;

            if ($position !== null && isset($mailed[$position->key()])) {
                $copy->sharesPositionMail();
            } elseif ($position !== null) {
                $mailed[$position->key()] = true;
            }

            $holder['user']->notify($copy);
            $notified->push($holder['user']);
        }

        return $notified;
    }
}
