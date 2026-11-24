<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela una selección antes de su primera hora (diseño 6.2). Las cargas que la publicación ya pasó a asignación se
 * quedan como están: el jefe sigue asignando a mano. No se borra nada.
 *
 * Toma primero el candado de la selección (pg_advisory_xact_lock sobre `staffing_selection:{id}`), el mismo que usan
 * los turnos de los docentes y el reloj, antes de cualquier bloqueo de fila.
 */
final class CancelSelection
{
    /**
     * @throws ValidationException si ya empezó o ya terminó
     */
    public function handle(Selection $selection, User $actor): void
    {
        DB::transaction(function () use ($selection, $actor): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if (! in_array($selection->status, [SelectionStatus::Draft, SelectionStatus::Published], true)) {
                throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: ya no se puede cancelar."]);
            }

            if ($selection->starts_at !== null && ! $selection->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => 'La selección ya empezó: solo se cancela antes de la primera hora.']);
            }

            $selection->update(['status' => SelectionStatus::Cancelled, 'cancelled_at' => now()]);

            activity()->performedOn($selection)->causedBy($actor)->event('selection_cancelled')->log('Canceló la selección');
        });
    }
}
