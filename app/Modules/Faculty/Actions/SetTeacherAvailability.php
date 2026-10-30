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
 * `day_end`; una que no, o repetida, rechaza todo sin escribir (error en `cells`).
 *
 * Solo cambia los bloques cuyo tipo difiere del que muestra la cuadrícula con lo guardado (AvailabilityRanges::kindOver):
 * de lo guardado quita el tramo de esos bloques (recortando o partiendo el rango que lo cruza) y pone el tipo nuevo,
 * unido con los rangos contiguos del mismo tipo que tocan esos bloques. Un rango que no toca ningún bloque cambiado se
 * queda como está, con su fila, aunque no caiga en los bloques del perfil (capturado con otro bloque u otro horario) o
 * siga a otro del mismo tipo; lo de otros días u horas del periodo, y otro periodo, no se tocan (supuestos por
 * confirmar). En una transacción bloquea al docente, para que dos guardados no se crucen; si ningún
 * bloque cambia, no escribe, no registra ni avisa. Si cambia, quita con borrado lógico las filas que ya no van, crea las
 * nuevas, deja una entrada de bitácora en el docente con el antes y el después del periodo y, después del commit, avisa
 * con AvailabilityChanged.
 */
final class SetTeacherAvailability
{
    /**
     * Da los minutos marcados en la cuadrícula por tipo, de las celdas ya revisadas, para el aviso de la pantalla.
     *
     * @param  list<array{day: int, starts_at: string, kind: AvailabilityKind|string}>  $cells
     * @return array{unavailable: int, preferred: int}
     *
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Term $term, array $cells, CampusProfile $profile): array
    {
        $wanted = [];
        $marked = [AvailabilityKind::Unavailable->value => 0, AvailabilityKind::Preferred->value => 0];

        foreach ($this->validated($cells, $profile) as $cell) {
            $wanted[$cell['day']][$cell['starts_at']] = $cell['kind'];
            $marked[$cell['kind']->value] += $profile->blockMinutes;
        }

        DB::transaction(function () use ($teacher, $term, $wanted, $profile): void {
            Teacher::query()->whereKey($teacher->getKey())->lockForUpdate()->first();

            $rows = TeacherAvailability::query()
                ->where('teacher_id', $teacher->getKey())
                ->where('term_id', $term->getKey())
                ->orderBy('day')
                ->orderBy('starts_at')
                ->get();
            $saved = array_map($this->rangeOf(...), $rows->all());
            $changed = $this->changedBlocks($saved, $wanted, $profile);

            if ($changed === []) {
                return;
            }

            $kept = $saved;

            foreach ($changed as $block) {
                $kept = AvailabilityRanges::cut($kept, $block['day'], $block['starts_at'], $block['ends_at']);
            }

            // Solo se unen los rangos que tocan un bloque cambiado; los demás se quedan como están.
            $touches = fn (array $range): bool => array_any($changed, fn (array $block): bool => $block['day'] === $range['day']
                && ($range['ends_at'] === $block['starts_at'] || $range['starts_at'] === $block['ends_at']));
            $near = array_values(array_filter($kept, $touches));
            $far = array_values(array_filter($kept, fn (array $range): bool => ! $touches($range)));
            $added = array_values(array_filter($changed, fn (array $block): bool => $block['kind'] !== null));
            $final = self::sorted([...$far, ...AvailabilityRanges::join([...$near, ...$added])]);
            $current = $rows->mapWithKeys(fn (TeacherAvailability $row): array => [self::key($this->rangeOf($row)) => $row])->all();
            $next = array_combine(array_map(self::key(...), $final), $final);

            TeacherAvailability::query()->whereKey(array_map(fn (TeacherAvailability $row): string => $row->getKey(), array_values(array_diff_key($current, $next))))->delete();

            foreach (array_diff_key($next, $current) as $range) {
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
                    'old' => ['availability' => array_map(self::logged(...), $saved)],
                ])
                ->withProperties(['term_id' => $term->getKey()])
                ->log('Cambió la disponibilidad del docente');

            AvailabilityChanged::dispatch($teacher, $term);
        });

        return $marked;
    }

    /**
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>  $ranges
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     */
    private static function sorted(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => [$a['day'], AvailabilityRanges::minutes($a['starts_at'])] <=> [$b['day'], AvailabilityRanges::minutes($b['starts_at'])]);

        return $ranges;
    }

    /**
     * Los bloques del perfil cuyo tipo pedido difiere del que la cuadrícula muestra con lo guardado, con el tipo nuevo
     * (null es disponible).
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>  $saved
     * @param  array<int, array<string, AvailabilityKind>>  $wanted
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: ?AvailabilityKind}>
     */
    private function changedBlocks(array $saved, array $wanted, CampusProfile $profile): array
    {
        $changed = [];
        $blocks = AvailabilityRanges::blocks($profile->dayStart, $profile->dayEnd, $profile->blockMinutes);

        foreach ($profile->days as $day) {
            $ofDay = array_filter($saved, fn (array $range): bool => $range['day'] === $day);

            foreach ($blocks as $block) {
                $kind = $wanted[$day][$block['starts_at']] ?? null;

                if (AvailabilityRanges::kindOver($ofDay, $block['starts_at'], $block['ends_at']) !== $kind) {
                    $changed[] = ['day' => $day, ...$block, 'kind' => $kind];
                }
            }
        }

        return $changed;
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
