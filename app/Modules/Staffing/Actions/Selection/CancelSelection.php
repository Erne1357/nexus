<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Notifications\SelectionCancelled;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela una selección antes de su primera hora (diseño 6.3, SEL-11): un borrador o una ya publicada. Las cargas que
 * la publicación ya pasó a asignación se quedan como están: el jefe sigue asignando a mano. No se borra nada.
 *
 * Si ya estaba publicada, los docentes de la cola ya tenían su turno: a cada uno con cuenta le llega
 * SelectionCancelled (campana y correo, después del commit), dirigido al puesto con que elige. Los de elección previa
 * (SEL-16) no tenían turno y no reciben el aviso. Un borrador no avisa a
 * nadie. La bitácora (`selection_cancelled`) guarda de qué estado se canceló y a cuántos se avisó.
 *
 * Toma primero el candado de la selección (pg_advisory_xact_lock sobre `staffing_selection:{id}`), el mismo que usan
 * los turnos de los docentes y el reloj, antes de cualquier bloqueo de fila.
 */
final class CancelSelection
{
    public function __construct(private readonly GrantTable $grants) {}

    /**
     * @throws ValidationException si ya empezó o ya terminó
     */
    public function handle(Selection $selection, User $actor): void
    {
        $recipients = DB::transaction(function () use ($selection, $actor): Collection {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if (! in_array($selection->status, [SelectionStatus::Draft, SelectionStatus::Published], true)) {
                throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: ya no se puede cancelar."]);
            }

            if ($selection->starts_at !== null && ! $selection->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => 'La selección ya empezó: solo se cancela antes de la primera hora.']);
            }

            $from = $selection->status;
            $recipients = $from === SelectionStatus::Published
                ? $selection->entries()->with('teacher.user')->whereNull('prior_choice_at')->get()->map(fn ($entry): ?User => $entry->teacher?->user)->filter()->unique('id')->values()
                : collect();

            $selection->update(['status' => SelectionStatus::Cancelled, 'cancelled_at' => now()]);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_cancelled')
                ->withProperties(['from' => $from->value, 'notified' => $recipients->count()])
                ->log($from === SelectionStatus::Published ? 'Canceló la selección publicada y avisó a la cola' : 'Canceló la selección');

            return $recipients;
        });

        if ($recipients->isEmpty()) {
            return;
        }

        $notice = new SelectionCancelled($selection->term_id, $selection->modality_id, (string) $selection->department()->value('name'), $selection->starts_at);
        $granting = $this->grants->rolesGranting(StaffingPermission::SelectionPickOwn);

        foreach ($recipients as $user) {
            $role = $user->roleAssignments()->current()->get()->first(fn ($assignment): bool => in_array($assignment->role, $granting, true))?->role;
            $user->notify($role === null ? clone $notice : (clone $notice)->addressedTo($role));
        }
    }
}
