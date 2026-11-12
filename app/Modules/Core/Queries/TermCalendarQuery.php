<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * El calendario efectivo de un periodo (M4, confirmado el 12 de noviembre de 2026: el calendario es por modalidad; ver
 * docs/dominio/reglas-de-negocio.md, Periodo y calendario): las 8 etapas en el orden de Stage, cada una con la ventana
 * de su modalidad si la tiene, si no la general, si no ninguna. Sin modalidad, solo las generales. Solo informa (D4).
 *
 * Se guardan en la caché de la aplicación las filas crudas, una entrada por periodo con la clave
 * `core.term-calendar.v2.{term_id}` que trae la ventana general y la de cada modalidad (por `general` o id de modalidad,
 * y por etapa); todas las lecturas salen de ella. El `v2` es por el cambio de forma de M4: la entrada vieja,
 * `core.term-calendar.{term_id}`, guardaba ids de campus, y con otra clave un despliegue nunca la lee. El estado respecto
 * a hoy (America/Ciudad_Juarez) se calcula en cada lectura, fuera de la caché. ForgetTermCalendar la borra al cambiar
 * el calendario.
 */
final class TermCalendarQuery
{
    /**
     * `$modality` es la modalidad o su id; null, solo las generales.
     *
     * @return list<StageWindow>
     */
    public function for(Term $term, Modality|string|null $modality): array
    {
        $rows = $this->cachedRows($term);
        $today = today()->toImmutable();
        $modalityId = $modality instanceof Modality ? $modality->getKey() : $modality;

        return array_map(function (Stage $stage) use ($rows, $modalityId, $today): StageWindow {
            $own = $modalityId === null ? null : $rows[$modalityId][$stage->value] ?? null;
            $general = $rows['general'][$stage->value] ?? null;
            [$row, $source] = match (true) {
                $own !== null => [$own, StageSource::Modality],
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
    public function upcoming(Term $term, Modality|string|null $modality, int $next = 2): array
    {
        $windows = $this->for($term, $modality);

        $inProgress = array_values(array_filter($windows, fn (StageWindow $window): bool => $window->status === StageStatus::InProgress));
        $upcoming = array_values(array_filter($windows, fn (StageWindow $window): bool => $window->status === StageStatus::Upcoming));

        usort($upcoming, fn (StageWindow $a, StageWindow $b): int => $a->startsOn <=> $b->startsOn);

        return [...$inProgress, ...array_slice($upcoming, 0, $next)];
    }

    /**
     * Las ventanas propias de cada modalidad (las que reemplazan a la general), por clave de modalidad, en el orden de
     * `$modalities`; una modalidad sin ventanas propias trae una lista vacía.
     *
     * @param  iterable<Modality>  $modalities
     * @return array<string, list<StageWindow>>
     */
    public function ownWindows(Term $term, iterable $modalities): array
    {
        $own = [];

        foreach ($modalities as $modality) {
            $own[$modality->code] = array_values(array_filter(
                $this->for($term, $modality),
                fn (StageWindow $window): bool => $window->source === StageSource::Modality,
            ));
        }

        return $own;
    }

    /**
     * Las ventanas guardadas de una etapa, la general y las de cada modalidad, tal como están en la base (sin la
     * caché), para editarlas: `modality_id` nulo es la general.
     *
     * @return Collection<int, TermStage>
     */
    public function storedWindows(Term $term, Stage $stage): Collection
    {
        return TermStage::query()->where('term_id', $term->getKey())->where('stage', $stage->value)->get();
    }

    /**
     * Cuántas ventanas (generales y de modalidad) tiene guardadas el periodo.
     */
    public function windowCount(Term $term): int
    {
        return TermStage::query()->where('term_id', $term->getKey())->count();
    }

    /**
     * Los periodos de los que se puede copiar el calendario a `$target`: los demás que tienen algo que copiar (una
     * ventana general o la de una modalidad activa y no borrada, lo mismo que filtra copyPreview), del más reciente al
     * más antiguo.
     *
     * @return Collection<int, Term>
     */
    public function copySources(Term $target): Collection
    {
        return Term::query()
            ->whereKeyNot($target->getKey())
            ->whereIn('id', TermStage::query()
                ->where(fn ($query) => $query->whereNull('modality_id')->orWhereIn('modality_id', Modality::query()->where('is_active', true)->select('id')))
                ->select('term_id'))
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Lo que haría copiar el calendario de `$source` a `$target`, sin escribir nada: los días del corrimiento (las
     * semanas completas entre los inicios de los dos periodos, redondeadas a la más cercana, para que cada ventana
     * conserve su día de la semana), las ventanas ya corridas (primero la general de cada etapa y luego las de
     * modalidad, en el orden de Stage y de clave de modalidad) y las ventanas de modalidad que no se copiarían porque
     * la modalidad está inactiva o borrada.
     *
     * @return array{shiftDays: int, windows: list<array{stage: Stage, modality: ?Modality, starts_on: CarbonImmutable, ends_on: CarbonImmutable}>, skipped: list<array{stage: Stage, modality: Modality}>}
     */
    public function copyPreview(Term $target, Term $source): array
    {
        $shiftDays = (int) (round($source->starts_on->startOfDay()->diffInDays($target->starts_on->startOfDay(), false) / 7) * 7);
        $stageOrder = array_flip(array_map(fn (Stage $stage): string => $stage->value, Stage::cases()));

        $stored = TermStage::query()
            ->where('term_id', $source->getKey())
            ->with(['modality' => fn ($query) => $query->withTrashed()])
            ->get()
            ->sortBy(fn (TermStage $window): string => sprintf('%02d-%d-%s', $stageOrder[$window->stage->value], $window->modality_id === null ? 0 : 1, $window->modality?->code ?? ''))
            ->values();

        $windows = [];
        $skipped = [];

        foreach ($stored as $window) {
            if ($window->modality !== null && (! $window->modality->is_active || $window->modality->trashed())) {
                $skipped[] = ['stage' => $window->stage, 'modality' => $window->modality];

                continue;
            }

            $windows[] = [
                'stage' => $window->stage,
                'modality' => $window->modality,
                'starts_on' => CarbonImmutable::instance($window->starts_on)->addDays($shiftDays)->startOfDay(),
                'ends_on' => CarbonImmutable::instance($window->ends_on)->addDays($shiftDays)->startOfDay(),
            ];
        }

        return ['shiftDays' => $shiftDays, 'windows' => $windows, 'skipped' => $skipped];
    }

    public static function key(string $termId): string
    {
        return 'core.term-calendar.v2.'.$termId;
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
     *                                                                                 modalidad, y por etapa
     */
    private function rows(Term $term): array
    {
        $rows = [];

        TermStage::query()
            ->where('term_id', $term->getKey())
            ->get()
            ->each(function (TermStage $window) use (&$rows): void {
                $rows[$window->modality_id ?? 'general'][$window->stage->value] = [
                    'starts_on' => $window->starts_on->toDateString(),
                    'ends_on' => $window->ends_on->toDateString(),
                ];
            });

        return $rows;
    }
}
