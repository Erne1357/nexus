<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-06. Resuelve a mano un renglón del CSV sin docente: lo empata con el docente dado o, con null, lo descarta de la
 * cola. Si el docente ya estaba agregado al final por no venir en el CSV, ese renglón se quita (ahora tiene sus
 * criterios); si ya tiene un renglón del CSV, se rechaza. Solo con la selección en borrador.
 */
final class ResolveSelectionEntry
{
    /**
     * @throws ValidationException
     */
    public function handle(SelectionEntry $entry, ?Teacher $teacher, User $actor): void
    {
        DB::transaction(function () use ($entry, $teacher, $actor): void {
            $selection = Selection::query()->lockForUpdate()->findOrFail($entry->selection_id);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Solo se resuelven renglones mientras la selección está en borrador.']);
            }

            $entry->setRawAttributes(SelectionEntry::query()->findOrFail($entry->getKey())->getAttributes(), sync: true);
            $name = $entry->csv_name;

            if ($teacher === null) {
                $entry->delete();
                $event = 'selection_entry_discarded';
                $log = "Descartó el renglón «{$name}» de la selección";
            } else {
                if (! RecomputeSelectionOrder::pool($selection)->whereKey($teacher->getKey())->exists()) {
                    throw ValidationException::withMessages(['teacher_id' => "{$teacher->name} no está activo o no está adscrito al departamento de la selección."]);
                }

                $other = $selection->entries()->where('teacher_id', $teacher->getKey())->whereKeyNot($entry->getKey())->first();

                if ($other !== null && $other->source === SelectionEntrySource::Csv) {
                    throw ValidationException::withMessages(['teacher_id' => "{$teacher->name} ya tiene su renglón en el CSV."]);
                }

                $other?->delete();
                $entry->update(['teacher_id' => $teacher->getKey(), 'hired_on' => $teacher->hired_on]);
                $event = 'selection_entry_resolved';
                $log = "Empató el renglón «{$name}» con {$teacher->name}";
            }

            app(RecomputeSelectionOrder::class)->handle($selection);

            activity()->performedOn($selection)->causedBy($actor)->event($event)
                ->withProperties(['entry_id' => (string) $entry->getKey(), 'csv_name' => $name, 'teacher_id' => $teacher?->getKey()])
                ->log($log);
        });
    }
}
