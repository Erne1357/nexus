<?php

namespace App\Modules\Staffing\Actions\Selection\Concerns;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionTurnQuery;
use Carbon\CarbonImmutable;
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
     * El renglón del docente, bloqueado, si ahora es su turno.
     *
     * @throws ValidationException
     */
    private function entryInTurn(Selection $selection, Teacher $teacher): SelectionEntry
    {
        $entry = SelectionEntry::query()
            ->where('selection_id', $selection->getKey())
            ->where('teacher_id', $teacher->getKey())
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['group' => 'No estás en la cola de esta selección.']);
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
}
