<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\GrantHourAllowance;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Staffing\Actions\Selection\Concerns\GuardsSelectionTurn;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use App\Modules\Staffing\Rules\LoadBand;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Terminar mi carga» (tarea 1.33, diseño 6.6, SEL-05 y SEL-12). Quién puede lo decide SelectionPolicy::pickOwn.
 *
 * En su turno y con el candado de la selección, suma las horas (teoría + práctica de la materia) de los grupos del
 * docente en las cargas incluidas y las compara con su base: la del CSV o, si no viene en él, la de sus plazas en Nexus
 * al inicio del periodo (TeacherBaseHoursQuery). Dentro de la banda de ±2 (LoadBand) termina. Fuera, solo con la
 * casilla de responsabilidad: con horas de más termina y se le autorizan esas horas en el periodo (GrantHourAllowance),
 * así HC-08 no las cuenta como choque mientras no le agreguen más (SEL-12); con menos pasa a la ronda extra
 * (`pending_extra`), y en la ronda extra termina, porque no hay otra. Terminar acaba su turno: ya no toma ni suelta.
 */
final class FinishMyLoad
{
    use GuardsSelectionTurn;

    public const string OUT_OF_BAND = 'Es responsabilidad del docente cubrir sus horas de acuerdo con su base: marca la casilla para continuar.';

    public function __construct(
        private readonly SelectionGroupsQuery $groups,
        private readonly TeacherBaseHoursQuery $baseHours,
        private readonly GrantHourAllowance $grantHours,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, Teacher $teacher, bool $acknowledged, User $actor): SelectionEntry
    {
        return DB::transaction(function () use ($selection, $teacher, $acknowledged, $actor): SelectionEntry {
            $selection = $this->lockSelection($selection);
            $entry = $this->entryInTurn($selection, $teacher);
            $term = Term::query()->findOrFail($selection->term_id);

            $hours = (int) $this->groups->of($selection)
                ->where('groups.teacher_id', $teacher->getKey())
                ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
                ->sum(DB::raw('subjects.theory_hours + subjects.practice_hours'));
            $base = $entry->source === SelectionEntrySource::Appended
                ? ($this->baseHours->forTerm($term, [$teacher->getKey()])[$teacher->getKey()] ?? 0)
                : $entry->base_hours;
            $band = LoadBand::classify($hours, $base);

            if ($band !== 'within' && ! $acknowledged) {
                throw ValidationException::withMessages(['acknowledged' => self::OUT_OF_BAND]);
            }

            $status = $band === 'under' && $selection->status !== SelectionStatus::ExtraRound
                ? SelectionEntryStatus::PendingExtra
                : SelectionEntryStatus::Done;

            $entry->update([
                'status' => $status,
                'closed_at' => now(),
                'closed_hours' => $hours,
                'out_of_band_acknowledged_at' => $band === 'within' ? null : now(),
            ]);

            if ($band === 'over') {
                $this->grantHours->handle($teacher, $term, $hours, "Cerró su carga con {$hours} h y base de {$base} h en la orden de selección (SEL-05).", $actor);
            }

            activity()
                ->performedOn($entry)
                ->causedBy($actor)
                ->event('selection_load_finished')
                ->withProperties(['selection_id' => $selection->getKey(), 'teacher_id' => $teacher->getKey(), 'hours' => $hours, 'base_hours' => $base, 'band' => $band, 'acknowledged' => $acknowledged, 'status' => $status->value])
                ->log("{$teacher->name} terminó su carga con {$hours} h de su base de {$base} h");

            return $entry;
        });
    }
}
