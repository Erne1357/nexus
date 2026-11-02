<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * El calendario efectivo de un periodo (S-A5, supuesto por confirmar): las 8 etapas en el orden de
 * Stage, cada una con la ventana de su campus si la tiene, si no la general, si no ninguna. Sin campus,
 * solo las generales. Solo informa (D4).
 *
 * Se guardan en la caché de la aplicación las filas crudas, con la clave
 * `core.term-calendar.{term_id}.{campus_id|all}`; el estado respecto a hoy (America/Ciudad_Juarez)
 * se calcula en cada lectura, fuera de la caché. ForgetTermCalendar la borra al cambiar el calendario.
 */
final class TermCalendarQuery
{
    /**
     * @return list<StageWindow>
     */
    public function for(Term $term, ?Campus $campus): array
    {
        $rows = Cache::remember(self::key($term->getKey(), $campus?->getKey()), now()->addDay(), fn (): array => $this->rows($term, $campus));
        $today = today()->toImmutable();

        return array_map(function (Stage $stage) use ($rows, $campus, $today): StageWindow {
            $own = $campus === null ? null : $rows[$campus->getKey()][$stage->value] ?? null;
            $general = $rows['general'][$stage->value] ?? null;
            [$row, $source] = match (true) {
                $own !== null => [$own, StageSource::Campus],
                $general !== null => [$general, StageSource::General],
                default => [null, StageSource::None],
            };

            if ($row === null) {
                return new StageWindow($stage, null, null, $source, StageStatus::Unscheduled, null);
            }

            $startsOn = CarbonImmutable::parse($row['starts_on'])->startOfDay();
            $endsOn = CarbonImmutable::parse($row['ends_on'])->startOfDay();

            return match (true) {
                $today < $startsOn => new StageWindow($stage, $startsOn, $endsOn, $source, StageStatus::Upcoming, (int) $today->diffInDays($startsOn)),
                $today > $endsOn => new StageWindow($stage, $startsOn, $endsOn, $source, StageStatus::Finished, null),
                default => new StageWindow($stage, $startsOn, $endsOn, $source, StageStatus::InProgress, (int) $today->diffInDays($endsOn)),
            };
        }, Stage::cases());
    }

    public static function key(string $termId, ?string $campusId): string
    {
        return 'core.term-calendar.'.$termId.'.'.($campusId ?? 'all');
    }

    /**
     * @return array<string, array<string, array{starts_on: string, ends_on: string}>> por `general` o
     *                                                                                 campus, y por etapa
     */
    private function rows(Term $term, ?Campus $campus): array
    {
        $rows = [];

        TermStage::query()
            ->where('term_id', $term->getKey())
            ->where(fn ($query) => $query->whereNull('campus_id')->when($campus !== null, fn ($query) => $query->orWhere('campus_id', $campus->getKey())))
            ->get()
            ->each(function (TermStage $window) use (&$rows): void {
                $rows[$window->campus_id ?? 'general'][$window->stage->value] = [
                    'starts_on' => $window->starts_on->toDateString(),
                    'ends_on' => $window->ends_on->toDateString(),
                ];
            });

        return $rows;
    }
}
