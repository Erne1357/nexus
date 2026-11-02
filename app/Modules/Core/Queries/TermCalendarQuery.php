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
 * El calendario efectivo de un periodo (ver docs/dominio/reglas-de-negocio.md, Periodo y calendario, supuesto por confirmar): las 8 etapas en el orden de
 * Stage, cada una con la ventana de su campus si la tiene, si no la general, si no ninguna. Sin campus,
 * solo las generales. Solo informa (D4).
 *
 * Se guardan en la caché de la aplicación las filas crudas, una entrada por periodo con la clave
 * `core.term-calendar.{term_id}` que trae la ventana general y la de cada campus (por `general` o id de campus, y
 * por etapa); todas las lecturas salen de ella. El estado respecto a hoy (America/Ciudad_Juarez)
 * se calcula en cada lectura, fuera de la caché. ForgetTermCalendar la borra al cambiar el calendario.
 */
final class TermCalendarQuery
{
    /**
     * @return list<StageWindow>
     */
    public function for(Term $term, ?Campus $campus): array
    {
        $rows = $this->cachedRows($term);
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

    /**
     * Los periodos de los que se puede copiar el calendario a `$target`: los demás que tienen al menos una
     * ventana, del más reciente al más antiguo.
     *
     * @return Collection<int, Term>
     */
    public function copySources(Term $target): Collection
    {
        return Term::query()
            ->whereKeyNot($target->getKey())
            ->whereIn('id', TermStage::query()->select('term_id'))
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Lo que haría copiar el calendario de `$source` a `$target`, sin escribir nada: los días del corrimiento (las
     * semanas completas entre los inicios de los dos periodos, redondeadas a la más cercana, para que cada ventana
     * conserve su día de la semana), las ventanas ya corridas (primero la general de cada etapa y luego las de
     * campus, en el orden de Stage y de código de campus) y las ventanas de campus que no se copiarían porque el
     * campus está inactivo o borrado.
     *
     * @return array{shiftDays: int, windows: list<array{stage: Stage, campus: ?Campus, starts_on: CarbonImmutable, ends_on: CarbonImmutable}>, skipped: list<array{stage: Stage, campus: Campus}>}
     */
    public function copyPreview(Term $target, Term $source): array
    {
        $shiftDays = (int) (round($source->starts_on->startOfDay()->diffInDays($target->starts_on->startOfDay(), false) / 7) * 7);
        $stageOrder = array_flip(array_map(fn (Stage $stage): string => $stage->value, Stage::cases()));

        $stored = TermStage::query()
            ->where('term_id', $source->getKey())
            ->with(['campus' => fn ($query) => $query->withTrashed()])
            ->get()
            ->sortBy(fn (TermStage $window): string => sprintf('%02d-%d-%s', $stageOrder[$window->stage->value], $window->campus_id === null ? 0 : 1, $window->campus?->code ?? ''))
            ->values();

        $windows = [];
        $skipped = [];

        foreach ($stored as $window) {
            if ($window->campus !== null && (! $window->campus->is_active || $window->campus->trashed())) {
                $skipped[] = ['stage' => $window->stage, 'campus' => $window->campus];

                continue;
            }

            $windows[] = [
                'stage' => $window->stage,
                'campus' => $window->campus,
                'starts_on' => CarbonImmutable::instance($window->starts_on)->addDays($shiftDays)->startOfDay(),
                'ends_on' => CarbonImmutable::instance($window->ends_on)->addDays($shiftDays)->startOfDay(),
            ];
        }

        return ['shiftDays' => $shiftDays, 'windows' => $windows, 'skipped' => $skipped];
    }

    public static function key(string $termId): string
    {
        return 'core.term-calendar.'.$termId;
    }

    /**
     * @return array<string, array<string, array{starts_on: string, ends_on: string}>>
     */
    private function cachedRows(Term $term): array
    {
        return Cache::remember(self::key($term->getKey()), now()->addDay(), fn (): array => $this->rows($term));
    }

    /**
     * @return array<string, array<string, array{starts_on: string, ends_on: string}>> por `general` o
     *                                                                                 campus, y por etapa
     */
    private function rows(Term $term): array
    {
        $rows = [];

        TermStage::query()
            ->where('term_id', $term->getKey())
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
