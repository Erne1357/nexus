<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-08. Guarda cuándo empieza la selección y su ventana diaria (hora de inicio y fin, días de la semana ISO) y
 * recalcula el turno de cada renglón y el fin de la primera ronda. Solo con la selección en borrador.
 *
 * Toma primero el candado de la selección (`staffing_selection:{id}`) porque recalcula el orden.
 */
final class ScheduleSelection
{
    /**
     * @param  list<int>  $weekdays  1 = lunes … 7 = domingo
     *
     * @throws ValidationException
     */
    public function handle(Selection $selection, CarbonImmutable $startsAt, string $windowStarts, string $windowEnds, array $weekdays, User $actor): Selection
    {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays);

        $errors = [];

        if ($weekdays === [] || array_diff($weekdays, [1, 2, 3, 4, 5, 6, 7]) !== []) {
            $errors['weekdays'] = 'Elige al menos un día de la semana válido.';
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $windowStarts) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $windowEnds) !== 1 || $windowStarts >= $windowEnds) {
            $errors['window_starts'] = 'La ventana debe empezar antes de terminar (formato HH:MM).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($selection, $startsAt, $windowStarts, $windowEnds, $weekdays, $actor): Selection {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Solo se programa una selección en borrador.']);
            }

            $selection->update([
                'starts_at' => $startsAt,
                'window_starts' => $windowStarts,
                'window_ends' => $windowEnds,
                'weekdays' => $weekdays,
            ]);

            app(RecomputeSelectionOrder::class)->handle($selection);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_scheduled')
                ->withProperties(['starts_at' => $startsAt->toIso8601String(), 'window' => "{$windowStarts}-{$windowEnds}", 'weekdays' => $weekdays])
                ->log('Programó la ventana de turnos de la selección');

            return $selection->refresh();
        });
    }
}
