<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

    /**
     * Lo que hay que ver del calendario efectivo en Inicio: las etapas en curso y las `$next` próximas que
     * siguen, por fecha de inicio (a igual fecha, en el orden de Stage). Vacío si no hay ninguna.
     *
     * @return list<StageWindow>
     */
    public function upcoming(Term $term, ?Campus $campus, int $next = 2): array
    {
        $windows = $this->for($term, $campus);

        $inProgress = array_values(array_filter($windows, fn (StageWindow $window): bool => $window->status === StageStatus::InProgress));
        $upcoming = array_values(array_filter($windows, fn (StageWindow $window): bool => $window->status === StageStatus::Upcoming));

        usort($upcoming, fn (StageWindow $a, StageWindow $b): int => $a->startsOn <=> $b->startsOn);

        return [...$inProgress, ...array_slice($upcoming, 0, $next)];
    }

    /**
     * Las ventanas propias de cada campus (las que reemplazan a la general), por código de campus, en el
     * orden de `$campuses`; un campus sin ventanas propias trae una lista vacía.
     *
     * @param  iterable<Campus>  $campuses
     * @return array<string, list<StageWindow>>
     */
    public function ownWindows(Term $term, iterable $campuses): array
    {
        $own = [];

        foreach ($campuses as $campus) {
            $own[$campus->code] = array_values(array_filter(
                $this->for($term, $campus),
                fn (StageWindow $window): bool => $window->source === StageSource::Campus,
            ));
        }

        return $own;
    }

    /**
     * Las ventanas guardadas de una etapa, la general y las de cada campus, tal como están en la base (sin la
     * caché), para editarlas: `campus_id` nulo es la general.
     *
     * @return Collection<int, TermStage>
     */
    public function storedWindows(Term $term, Stage $stage): Collection
    {
        return TermStage::query()->where('term_id', $term->getKey())->where('stage', $stage->value)->get();
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
