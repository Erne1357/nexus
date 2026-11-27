<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\GrantHourAllowance;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\Selection\Concerns\GuardsSelectionTurn;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use App\Modules\Staffing\Queries\TeachingTargetQuery;
use App\Modules\Staffing\Rules\LoadBand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Terminar mi carga» (tarea 1.33, diseño 6.6, SEL-05 y SEL-12). Quién puede lo decide SelectionPolicy::pickOwn.
 *
 * En su turno y con el candado de la selección, suma las horas (teoría + práctica de la materia) de los grupos del
 * docente en las cargas incluidas y las compara con su base: la del CSV o, si no viene en él, la de sus plazas en Nexus
 * al inicio del periodo (TeacherBaseHoursQuery). Dentro de la banda de ±2 (LoadBand) termina. Fuera, solo con la
 * casilla de responsabilidad: con horas de más termina; con menos pasa a la ronda extra (`pending_extra`), y en la
 * ronda extra termina, porque no hay otra. Terminar acaba su turno: ya no toma ni suelta.
 *
 * SEL-12: cada cierre que termina (`done`) le autoriza en el periodo (GrantHourAllowance) todo lo que HC-08 le cuenta en
 * ese momento: sus grupos de cualquier carga, modalidad o campus más sus horas externas (ScheduleValidator::
 * teacherTermHours). Así lo que cerró, dentro de la banda o con la casilla, no es choque duro de HC-08 aunque su base de
 * Nexus sea menor que la del CSV; lo que le agreguen después sí.
 *
 * SEL-14: confirm() es el mismo cierre para quien quedó sin resolver al terminar la selección («Confirmar mi carga»).
 */
final class FinishMyLoad
{
    use GuardsSelectionTurn;

    public const string OUT_OF_BAND = 'Es responsabilidad del docente cubrir sus horas frente a grupo de acuerdo con su plaza: marca la casilla para continuar.';

    public const string NOT_UNRESOLVED = 'Solo confirma su carga quien quedó sin resolver al terminar la selección.';

    public function __construct(
        private readonly SelectionGroupsQuery $groups,
        private readonly TeachingTargetQuery $targets,
        private readonly GrantHourAllowance $grantHours,
        private readonly ScheduleValidator $validator,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, Teacher $teacher, bool $acknowledged, User $actor): SelectionEntry
    {
        return DB::transaction(function () use ($selection, $teacher, $acknowledged, $actor): SelectionEntry {
            $selection = $this->lockSelection($selection);
            $entry = $this->entryInTurn($selection, $teacher, $actor);

            return $this->close($selection, $entry, $teacher, $acknowledged, $actor, confirmation: false);
        });
    }

    /**
     * SEL-14 (confirmado el 24 de noviembre de 2026): «Confirmar mi carga» del docente que quedó sin resolver
     * (`unresolved`) al terminar la ronda extra, con la selección ya terminada, fuera de cualquier turno. Es el mismo
     * cierre que «Terminar mi carga»: la banda de ±2 y, fuera de ella, la casilla obligatoria; con o sin ella termina
     * (`done`), porque ya no hay ronda, y le autoriza en el periodo el total de HC-08 de ese momento (SEL-12). Hasta
     * entonces sus horas de más siguen siendo choque duro. No toma ni suelta grupos: eso sigue siendo solo en su turno.
     * Mismo candado de la selección y la misma regla de que solo actúa el propio docente.
     *
     * @throws AuthorizationException si el docente no es el del usuario
     * @throws ValidationException
     */
    public function confirm(Selection $selection, Teacher $teacher, bool $acknowledged, User $actor): SelectionEntry
    {
        return DB::transaction(function () use ($selection, $teacher, $acknowledged, $actor): SelectionEntry {
            $selection = $this->lockSelection($selection);
            $entry = $this->ownEntry($selection, $teacher, $actor);

            if ($entry->status !== SelectionEntryStatus::Unresolved || ! in_array($selection->status, [SelectionStatus::ExtraRound, SelectionStatus::Finished], true)) {
                throw ValidationException::withMessages(['group' => self::NOT_UNRESOLVED]);
            }

            return $this->close($selection, $entry, $teacher, $acknowledged, $actor, confirmation: true);
        });
    }

    /**
     * Mide sus horas contra su base, exige la casilla fuera de la banda y cierra el renglón: con menos horas en la
     * primera ronda queda para la ronda extra; en cualquier otro caso termina y se justifican sus horas (SEL-12).
     *
     * @throws ValidationException
     */
    private function close(Selection $selection, SelectionEntry $entry, Teacher $teacher, bool $acknowledged, User $actor, bool $confirmation): SelectionEntry
    {
        $term = Term::query()->findOrFail($selection->term_id);

        $hours = (int) $this->groups->of($selection)
            ->where('groups.teacher_id', $teacher->getKey())
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->sum(DB::raw('subjects.theory_hours + subjects.practice_hours'));
        $base = $this->targets->forTerm($term, [$teacher->getKey() => (int) $entry->base_hours])[$teacher->getKey()] ?? 0;
        $band = LoadBand::classify($hours, $base);

        if ($band !== 'within' && ! $acknowledged) {
            throw ValidationException::withMessages(['acknowledged' => self::OUT_OF_BAND]);
        }

        $status = ! $confirmation && $band === 'under' && $selection->status !== SelectionStatus::ExtraRound
            ? SelectionEntryStatus::PendingExtra
            : SelectionEntryStatus::Done;

        $entry->update([
            'status' => $status,
            'closed_at' => now(),
            'closed_hours' => $hours,
            'out_of_band_acknowledged_at' => $band === 'within' ? null : now(),
        ]);

        if ($status === SelectionEntryStatus::Done) {
            $this->justifyHours($teacher, $term, $base, $actor);
        }

        activity()
            ->performedOn($entry)
            ->causedBy($actor)
            ->event($confirmation ? 'selection_load_confirmed' : 'selection_load_finished')
            ->withProperties(['selection_id' => $selection->getKey(), 'teacher_id' => $teacher->getKey(), 'hours' => $hours, 'base_hours' => $base, 'band' => $band, 'acknowledged' => $acknowledged, 'status' => $status->value])
            ->log($confirmation
                ? "{$teacher->name} confirmó su carga con {$hours} h (frente a grupo: {$base} h) después de la selección"
                : "{$teacher->name} terminó su carga con {$hours} h (frente a grupo: {$base} h)");

        return $entry;
    }

    /**
     * SEL-12: autoriza el total de HC-08 del docente al cerrar (asignadas en todo el periodo más externas). Sin horas no
     * hay nada que autorizar.
     */
    private function justifyHours(Teacher $teacher, Term $term, int $base, User $actor): void
    {
        $this->validator->forget();
        $count = $this->validator->teacherTermHours($teacher, $term);
        $total = $count === null ? 0 : $count['assigned_hours'] + $count['external_hours'];

        if ($total > 0) {
            $this->grantHours->handle($teacher, $term, $total, "Cerró su carga con {$total} h de HC-08 (asignadas y externas) y {$base} h frente a grupo en la orden de selección (SEL-05).", $actor);
            $this->validator->forget();
        }
    }
}
