<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Rules\AvailabilityRanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Guarda la disponibilidad de un docente en un periodo desde la cuadrícula día × hora (S12, supuesto por confirmar).
 * Quién puede lo decide TeacherPolicy (`manageAvailability`) antes de llamarla.
 *
 * Las celdas son los bloques marcados (no disponible o preferido) del perfil de la cuadrícula; lo que no viene se
 * da por disponible. Cada celda debe caer en un día del perfil y empezar en uno de sus bloques, entre `day_start` y
 * `day_end`; una que no, o repetida, rechaza todo sin escribir (error en `cells`). Se guardan como rangos unidos
 * (AvailabilityRanges).
 *
 * Reemplaza lo de esos días y ese horario en el periodo; lo que el docente tenga en otros días u horas (capturado con
 * el perfil de otro campus) se conserva, también el pedazo de un rango que se sale del horario. Otro periodo no se
 * toca. En una transacción bloquea al docente, para que dos guardados no se crucen, y compara con lo guardado: si
 * nada cambia, no escribe, no registra ni avisa; si cambia, quita con borrado lógico las filas que ya no van, crea
 * las nuevas, deja una entrada de bitácora en el docente con el antes y el después del periodo y, después del commit,
 * avisa con AvailabilityChanged.
 */
final class SetTeacherAvailability
{
    /**
     * @param  list<array{day: int, starts_at: string, kind: AvailabilityKind|string}>  $cells
     *
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Term $term, array $cells, CampusProfile $profile): void
    {
        $ranges = AvailabilityRanges::merge($this->validated($cells, $profile), $profile->blockMinutes);

        DB::transaction(function () use ($teacher, $term, $ranges, $profile): void {
            Teacher::query()->whereKey($teacher->getKey())->lockForUpdate()->first();

            $rows = TeacherAvailability::query()
                ->where('teacher_id', $teacher->getKey())
                ->where('term_id', $term->getKey())
                ->orderBy('day')
                ->orderBy('starts_at')
                ->get();

            $current = $rows->mapWithKeys(fn (TeacherAvailability $row): array => [self::key($this->rangeOf($row)) => $row])->all();
            $kept = array_merge(...array_map(fn (TeacherAvailability $row): array => $this->outside($this->rangeOf($row), $profile), $rows->all()));
            $final = AvailabilityRanges::join([...$kept, ...$ranges]);
            $wanted = array_combine(array_map(self::key(...), $final), $final);

            $removed = array_diff_key($current, $wanted);
            $added = array_diff_key($wanted, $current);

            if ($removed === [] && $added === []) {
                return;
            }

            TeacherAvailability::query()->whereKey(array_map(fn (TeacherAvailability $row): string => $row->getKey(), array_values($removed)))->delete();

            foreach ($added as $range) {
                TeacherAvailability::query()->create([
                    'teacher_id' => $teacher->getKey(),
                    'term_id' => $term->getKey(),
                    ...$range,
                ]);
            }

            activity()
                ->performedOn($teacher)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => ['availability' => array_map(self::logged(...), $final)],
                    'old' => ['availability' => array_map(fn (TeacherAvailability $row): array => self::logged($this->rangeOf($row)), $rows->all())],
                ])
                ->withProperties(['term_id' => $term->getKey()])
                ->log('Cambió la disponibilidad del docente');

            AvailabilityChanged::dispatch($teacher, $term);
        });
    }

    /**
     * Las celdas con su forma revisada contra el perfil.
     *
     * @param  array<int, mixed>  $cells
     * @return list<array{day: int, starts_at: string, kind: AvailabilityKind}>
     *
     * @throws ValidationException
     */
    private function validated(array $cells, CampusProfile $profile): array
    {
        $start = AvailabilityRanges::minutes($profile->dayStart);
        $end = AvailabilityRanges::minutes($profile->dayEnd);
        $schedule = $profile->schedule();
        $valid = [];
        $seen = [];

        foreach ($cells as $cell) {
            $day = is_array($cell) ? ($cell['day'] ?? null) : null;
            $time = is_array($cell) ? ($cell['starts_at'] ?? null) : null;
            $kind = is_array($cell) ? ($cell['kind'] ?? null) : null;
            $kind = $kind instanceof AvailabilityKind ? $kind : (is_string($kind) ? AvailabilityKind::tryFrom($kind) : null);

            if (! is_int($day) || ! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1 || $kind === null) {
                throw self::invalid('Una celda de la cuadrícula no es válida. Vuelve a cargar la página.');
            }

            $dayName = AvailabilityRanges::DAY_NAMES[$day] ?? null;

            if ($dayName === null || ! in_array($day, $profile->days, true)) {
                throw self::invalid('El '.mb_strtolower($dayName ?? "día {$day}")." no está en el horario del campus ({$schedule}).");
            }

            $minutes = AvailabilityRanges::minutes($time);

            if ($minutes < $start || $minutes + $profile->blockMinutes > $end || ($minutes - $start) % $profile->blockMinutes !== 0) {
                throw self::invalid('Las '.$time.' del '.mb_strtolower($dayName)." no son un bloque del horario del campus ({$schedule}, bloques de {$profile->blockMinutes} minutos).");
            }

            if (isset($seen[$day][$time])) {
                throw self::invalid('Las '.$time.' del '.mb_strtolower($dayName).' vienen dos veces.');
            }

            $seen[$day][$time] = true;
            $valid[] = ['day' => $day, 'starts_at' => $time, 'kind' => $kind];
        }

        return $valid;
    }

    /**
     * Lo de un rango guardado que queda fuera de la cuadrícula del perfil y se conserva: todo, si su día no está en el
     * perfil; si no, lo que empieza antes de `day_start` o termina después de `day_end`.
     *
     * @param  array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}  $range
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     */
    private function outside(array $range, CampusProfile $profile): array
    {
        if (! in_array($range['day'], $profile->days, true)) {
            return [$range];
        }

        $start = AvailabilityRanges::minutes($profile->dayStart);
        $end = AvailabilityRanges::minutes($profile->dayEnd);
        $from = AvailabilityRanges::minutes($range['starts_at']);
        $to = AvailabilityRanges::minutes($range['ends_at']);
        $parts = [];

        if ($from < $start) {
            $parts[] = [...$range, 'ends_at' => AvailabilityRanges::clock(min($to, $start))];
        }

        if ($to > $end) {
            $parts[] = [...$range, 'starts_at' => AvailabilityRanges::clock(max($from, $end))];
        }

        return $parts;
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}
     */
    private function rangeOf(TeacherAvailability $row): array
    {
        return ['day' => $row->day, 'starts_at' => substr($row->starts_at, 0, 5), 'ends_at' => substr($row->ends_at, 0, 5), 'kind' => $row->kind];
    }

    /**
     * @param  array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}  $range
     */
    private static function key(array $range): string
    {
        return "{$range['day']}|{$range['starts_at']}|{$range['ends_at']}|{$range['kind']->value}";
    }

    /**
     * @param  array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}  $range
     * @return array{day: int, starts_at: string, ends_at: string, kind: string}
     */
    private static function logged(array $range): array
    {
        return [...$range, 'kind' => $range['kind']->value];
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['cells' => $message]);
    }
}
