<?php

namespace App\Modules\Staffing\Actions\Selection\Concerns;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionTurnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Para las Actions del docente en su turno (tomar, soltar y terminar; tarea 1.33, diseño 6.14): serializa todo lo de una
 * selección y exige que sea su turno. Se llama al empezar la transacción de la Action.
 *
 * Orden de candados: primero el candado consultivo de la selección (`pg_advisory_xact_lock`), luego el renglón del
 * docente y después, como AssignTeacher, la carga en compartido (LoadEditability::lockLoad) y el grupo. Nadie más toma
 * ese candado consultivo, así que no cierra un ciclo con las Actions del jefe.
 */
trait GuardsSelectionTurn
{
    /**
     * Toma el candado de la selección hasta el fin de la transacción y la relee (su estado pudo cambiar mientras
     * esperaba).
     */
    private function lockSelection(Selection $selection): Selection
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

        return Selection::query()->findOrFail($selection->getKey());
    }

    /**
     * El renglón del docente, bloqueado, si quien actúa es el propio docente y ahora es su turno. SelectionPolicy::pickOwn
     * solo prueba que el usuario tiene un renglón; aquí se exige que el docente sea el suyo, para que una pantalla que
     * reciba el docente del cliente no deje actuar por otro.
     *
     * @throws AuthorizationException si el docente no es el del usuario (o no tiene cuenta)
     * @throws ValidationException
     */
    private function entryInTurn(Selection $selection, Teacher $teacher, User $actor): SelectionEntry
    {
        $entry = $this->ownEntry($selection, $teacher, $actor);

        if ($entry->isPriorChoice()) {
            throw ValidationException::withMessages(['group' => 'Tus grupos ya vienen de la elección previa: no tienes turno. Solo tu jefe de departamento o el JPD cambian tu carga (SEL-16).']);
        }

        if ($entry->status === SelectionEntryStatus::Done) {
            throw ValidationException::withMessages(['group' => 'Ya terminaste tu carga: solo tu jefe de departamento o el JPD la cambian.']);
        }

        if ($entry->status === SelectionEntryStatus::PendingExtra && $entry->closed_at !== null && $selection->status !== SelectionStatus::ExtraRound) {
            throw ValidationException::withMessages(['group' => 'Ya cerraste tu carga en esta ronda: vuelves a elegir en la ronda extra.']);
        }

        if (! app(SelectionTurnQuery::class)->isTurnOf($selection, $teacher, CarbonImmutable::now())) {
            throw ValidationException::withMessages(['group' => 'No es tu turno.']);
        }

        return $entry;
    }

    /**
     * El renglón del docente, bloqueado, si quien actúa es el propio docente (sin mirar el turno).
     *
     * @throws AuthorizationException si el docente no es el del usuario (o no tiene cuenta)
     * @throws ValidationException si no está en la cola
     */
    private function ownEntry(Selection $selection, Teacher $teacher, User $actor): SelectionEntry
    {
        if ($teacher->user_id === null || $teacher->user_id !== $actor->getKey()) {
            throw new AuthorizationException('Solo el propio docente elige, suelta, termina o confirma su carga.');
        }

        $entry = SelectionEntry::query()
            ->where('selection_id', $selection->getKey())
            ->where('teacher_id', $teacher->getKey())
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['group' => 'No estás en la cola de esta selección.']);
        }

        return $entry;
    }
}
