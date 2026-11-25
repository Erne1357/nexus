<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\GrantHourAllowance;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\Selection\Concerns\GuardsSelectionTurn;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\PriorChoiceHoursQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-16 ampliada (tarea 1.45, 25 de noviembre de 2026): el docente con elección previa cuyas horas en las cargas de la
 * selección quedan arriba de la banda de su base (LoadBand, base + 2) confirma con la casilla de responsabilidad de
 * SEL-05 y SEL-12. Hasta entonces PublishSelection no le autorizó HC-08 y sus horas de más son choque. Al confirmar se
 * guarda `out_of_band_acknowledged_at`, se le autoriza el total de HC-08 del periodo (GrantHourAllowance) y queda en la
 * bitácora.
 *
 * Solo lo confirma el propio docente (AuthorizationException si no), con la selección publicada, en curso, en ronda extra
 * o terminada, y el mismo candado consultivo de las demás Actions de la selección. Idempotente: si ya confirmó no escribe
 * nada. Si sus horas no pasan de la banda no hay nada que confirmar.
 */
final class ConfirmPriorChoiceHours
{
    use GuardsSelectionTurn;

    public const string NOT_PRIOR = 'Solo confirma sus horas quien trae elección previa.';

    public const string NOT_OVER = 'Tus horas no pasan de tu banda: no hay nada que confirmar.';

    public const string ACKNOWLEDGE = 'Marca la casilla de que estás de acuerdo con tener más horas que tu base para continuar.';

    public const string NOT_OPEN = 'La selección no está publicada: todavía no hay horas que confirmar.';

    public function __construct(
        private readonly PriorChoiceHoursQuery $priorHours,
        private readonly GrantHourAllowance $grantHours,
        private readonly ScheduleValidator $validator,
    ) {}

    /**
     * @throws AuthorizationException si el docente no es el del usuario
     * @throws ValidationException
     */
    public function handle(Selection $selection, Teacher $teacher, bool $acknowledged, User $actor): SelectionEntry
    {
        return DB::transaction(function () use ($selection, $teacher, $acknowledged, $actor): SelectionEntry {
            $selection = $this->lockSelection($selection);
            $entry = $this->ownEntry($selection, $teacher, $actor);

            if (! in_array($selection->status, [SelectionStatus::Published, SelectionStatus::Running, SelectionStatus::ExtraRound, SelectionStatus::Finished], true)) {
                throw ValidationException::withMessages(['selection' => self::NOT_OPEN]);
            }

            if (! $entry->isPriorChoice()) {
                throw ValidationException::withMessages(['group' => self::NOT_PRIOR]);
            }

            if ($entry->out_of_band_acknowledged_at !== null) {
                return $entry;
            }

            $measured = $this->priorHours->forSelection($selection, collect([$entry]))[$entry->getKey()];

            if ($measured['band'] !== 'over') {
                throw ValidationException::withMessages(['group' => self::NOT_OVER]);
            }

            if (! $acknowledged) {
                throw ValidationException::withMessages(['acknowledged' => self::ACKNOWLEDGE]);
            }

            $entry->update(['out_of_band_acknowledged_at' => now(), 'closed_hours' => $measured['hours']]);
            $total = $this->grant($teacher, Term::query()->findOrFail($selection->term_id), $measured['base'], $actor);

            activity()
                ->performedOn($entry)
                ->causedBy($actor)
                ->event('prior_choice_hours_confirmed')
                ->withProperties(['selection_id' => $selection->getKey(), 'teacher_id' => $teacher->getKey(), 'hours' => $measured['hours'], 'base_hours' => $measured['base'], 'allowance_hours' => $total])
                ->log("{$teacher->name} confirmó sus {$measured['hours']} h de elección previa con base de {$measured['base']} h");

            return $entry;
        });
    }

    /**
     * Autoriza todo lo que HC-08 le cuenta en el periodo, como SEL-12; devuelve el total.
     */
    private function grant(Teacher $teacher, Term $term, int $base, User $actor): int
    {
        $this->validator->forget();
        $count = $this->validator->teacherTermHours($teacher, $term);
        $total = $count === null ? 0 : $count['assigned_hours'] + $count['external_hours'];

        if ($total > 0) {
            $this->grantHours->handle($teacher, $term, $total, "Elección previa, confirmada por el docente: {$total} h de HC-08 (asignadas y externas) con base de {$base} h (SEL-16).", $actor);
            $this->validator->forget();
        }

        return $total;
    }
}
