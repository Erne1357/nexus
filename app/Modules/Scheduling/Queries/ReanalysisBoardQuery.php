<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisDiffQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\AcademicLoad\Rules\SnapshotDiffText;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Demand\Rules\DemandSettings;
use Illuminate\Support\Str;
use LogicException;

/**
 * Los bloques de «Re-análisis de la carga» (tarea 8.2, pantalla; opción B «Por materia», elegida por el usuario el 18 de
 * noviembre de 2026): el diff de demanda de la carga (ReanalysisDiffQuery: RA-04, RA-07, RA-09) agrupado por materia y
 * campus, con los filtros de la pantalla, y lo que el jefe ya cambió contra la versión vigente (ReanalysisChangesQuery,
 * RA-10), por bloque («Ya ajustaste E: grupo nuevo») y en lista. Solo lee; se calcula al pintar, sobre la proyección
 * vigente, como el diff.
 *
 * Los bloques van primero los que tienen faltantes (la mayor falta primero), luego los que tienen sobrantes, luego los
 * ajustados y al final los de solo primer semestre; a igual cosa, por clave de la materia y del campus.
 */
final class ReanalysisBoardQuery
{
    /** Las clases del filtro: las de DemandBalance y `new`, las de primer semestre (RA-09). */
    public const array CLASSES = ['shortfall', 'surplus', 'adjusted', 'new'];

    private const array ORDER = ['shortfall' => 0, 'surplus' => 1, 'adjusted' => 2, 'new' => 3];

    public function __construct(
        private readonly ReanalysisDiffQuery $diff,
        private readonly ReanalysisChangesQuery $changes,
    ) {}

    /**
     * Lo que se lee de la base para el tablero de quien ve `$careerIds` (null, todas; RA-07): el diff, el umbral y, con
     * `$withChanges`, lo que ya cambió. Una vez por petición; los filtros no lo vuelven a pedir (#620).
     *
     * @param  list<string>|null  $careerIds
     */
    public function data(AcademicLoad $load, ?array $careerIds, bool $withChanges): ReanalysisBoardData
    {
        return new ReanalysisBoardData(
            diff: $this->diff->forLoad($load, $careerIds),
            threshold: DemandSettings::fromSettings()->highlightThreshold,
            changes: $withChanges ? $this->changesOf($load) : null,
        );
    }

    /**
     * El tablero de lo leído con los filtros de la pantalla: `class` (CLASSES o ''), `review` (solo las marcadas para
     * revisar o sin historia), `career` (id o ''), `campus` (id o null) y `search` (clave o nombre de la materia, sin
     * importar acentos). Con los cambios, también lo que ya cambió. No lee la base más que los campus de los bloques.
     *
     * @param  array{class: string, review: bool, career: string, campus: ?string, search: string}  $filters
     */
    public function of(ReanalysisBoardData $data, array $filters): ReanalysisBoard
    {
        $diff = $data->diff;
        $threshold = $data->threshold;
        $touched = $this->touchedByBlock($data->changes['diff'] ?? null);
        $search = $this->normalize($filters['search']);

        $base = array_values(array_filter($diff->rows, fn (ReanalysisRow $row): bool => ($filters['career'] === '' || $row->careerId === $filters['career'])
            && ($search === '' || str_contains($this->normalize("{$row->subjectCode} {$row->subjectName}"), $search))));
        $inCampus = array_values(array_filter($base, fn (ReanalysisRow $row): bool => $filters['campus'] === null || $row->campusId === $filters['campus']));
        $ofClass = fn (ReanalysisRow $row): bool => ($filters['class'] === '' || self::classOf($row) === $filters['class'])
            && (! $filters['review'] || $row->needsReview || $row->insufficientHistory);
        $shown = array_values(array_filter($inCampus, $ofClass));

        $campusCounts = [];

        foreach (array_filter($base, $ofClass) as $row) {
            if ($row->campusCode !== null) {
                $campusCounts[$row->campusCode] = ($campusCounts[$row->campusCode] ?? 0) + 1;
            }
        }

        return new ReanalysisBoard(
            blocks: $this->blocks($diff->blockGroups, $shown, $threshold, $touched, $filters['class'] !== '' || $filters['review']),
            chips: $this->chips($inCampus),
            campusCounts: $campusCounts,
            campusTotal: count(array_filter($base, $ofClass)),
            careers: $this->careers($diff->rows),
            threshold: $threshold,
            rowCount: count($diff->rows),
            snapshotVersion: $diff->snapshotVersion,
            forecastComputedAt: $diff->forecastComputedAt,
            changes: $data->changes['entries'] ?? null,
        );
    }

    /**
     * Un bloque, para el drawer de sugerencias (8.3): la materia en el campus (null, sin campus) con todas las carreras
     * que el usuario ve, sin los filtros de la pantalla. Null si la materia no está en el diff.
     *
     * @param  list<string>|null  $careerIds
     */
    public function block(AcademicLoad $load, ?array $careerIds, string $subjectId, ?string $campusId): ?ReanalysisBlock
    {
        $diff = $this->diff->forLoad($load, $careerIds);
        $rows = array_values(array_filter(
            $diff->rows,
            fn (ReanalysisRow $row): bool => $row->subjectId === $subjectId && $row->campusId === $campusId,
        ));

        return $this->blocks($diff->blockGroups, $rows, DemandSettings::fromSettings()->highlightThreshold, [], false)[0] ?? null;
    }

    /**
     * La clase de un renglón para el filtro: la de su balance, o `new` en primer semestre (RA-09).
     */
    public static function classOf(ReanalysisRow $row): string
    {
        return $row->balance?->value ?? 'new';
    }

    /**
     * @param  array<string, list<list<string>>>  $groups  los grupos de la versión por materia y campus, con sus carreras
     * @param  list<ReanalysisRow>  $rows
     * @param  array<string, list<string>>  $touched
     * @return list<ReanalysisBlock>
     */
    private function blocks(array $groups, array $rows, int $threshold, array $touched, bool $filtered): array
    {
        if ($rows === []) {
            return [];
        }

        $byBlock = [];

        foreach ($rows as $row) {
            $byBlock[$row->subjectId.'|'.($row->campusId ?? '')][] = $row;
        }

        $campuses = Campus::withTrashed()->whereIn('id', array_values(array_filter(array_unique(array_map(fn (ReanalysisRow $row): ?string => $row->campusId, $rows)))))->get()->keyBy('id');
        $blocks = [];

        foreach ($byBlock as $key => $blockRows) {
            usort($blockRows, fn (ReanalysisRow $a, ReanalysisRow $b): int => [self::ORDER[self::classOf($a)], -abs(ReanalysisBoard::percent($a) ?? 999), $a->careerCode]
                <=> [self::ORDER[self::classOf($b)], -abs(ReanalysisBoard::percent($b) ?? 999), $b->careerCode]);
            $first = $blockRows[0];
            $careers = array_map(fn (ReanalysisRow $row): string => $row->careerId, $blockRows);
            $blockTouched = $touched[$first->subjectId.'|'.($first->campusCode ?? '')] ?? [];
            $calm = array_all($blockRows, fn (ReanalysisRow $row): bool => in_array($row->balance, [DemandBalance::Adjusted, null], true) && ! $row->needsReview && ! $row->insufficientHistory);
            $top = max(array_map(fn (ReanalysisRow $row): float => max($row->offeredSeats, ReanalysisBoard::shownStudents($row) * (1 + $threshold / 100)), $blockRows));

            $blocks[] = new ReanalysisBlock(
                key: $key,
                subjectId: $first->subjectId,
                subjectCode: $first->subjectCode,
                subjectName: $first->subjectName,
                campus: $first->campusId === null ? null : $campuses->get($first->campusId),
                rows: $blockRows,
                expected: array_sum(array_map(ReanalysisBoard::shownStudents(...), $blockRows)),
                seats: array_sum(array_map(fn (ReanalysisRow $row): int => $row->offeredSeats, $blockRows)),
                groups: count(array_filter($groups[$key] ?? [], fn (array $groupCareers): bool => array_intersect($groupCareers, $careers) !== [])),
                compact: $calm && $blockTouched === [] && ! $filtered,
                touched: $blockTouched,
                scale: max(1.0, $top * 1.05),
            );
        }

        usort($blocks, fn (ReanalysisBlock $a, ReanalysisBlock $b): int => [...$this->severity($a), $a->subjectCode, $a->campus?->code ?? '']
            <=> [...$this->severity($b), $b->subjectCode, $b->campus?->code ?? '']);

        return $blocks;
    }

    /**
     * El orden de un bloque: [grupo, cuánto]. Con faltantes, la mayor falta primero; con sobrantes, el mayor sobrante
     * primero; luego los ajustados y al final los de solo primer semestre.
     *
     * @return array{0: int, 1: int}
     */
    private function severity(ReanalysisBlock $block): array
    {
        $gap = fn (DemandBalance $balance): int => array_sum(array_map(fn (ReanalysisRow $row): int => $row->gap(), array_filter($block->rows, fn (ReanalysisRow $row): bool => $row->balance === $balance)));
        $has = fn (DemandBalance $balance): bool => array_any($block->rows, fn (ReanalysisRow $row): bool => $row->balance === $balance);

        return match (true) {
            $has(DemandBalance::Shortfall) => [0, -$gap(DemandBalance::Shortfall)],
            $has(DemandBalance::Surplus) => [1, $gap(DemandBalance::Surplus)],
            $block->onlyFirstSemester() => [3, 0],
            default => [2, 0],
        };
    }

    /**
     * Las cifras de los filtros de clase: faltantes, sobrantes, ajustadas, de nuevo ingreso y por revisar.
     *
     * @param  list<ReanalysisRow>  $rows
     * @return list<array{key: string, label: string, icon: string, tone: string, count: int}>
     */
    private function chips(array $rows): array
    {
        $count = fn (callable $matches): int => count(array_filter($rows, $matches));
        $chips = array_map(fn (DemandBalance $balance): array => [
            'key' => $balance->value,
            'label' => $balance->plural(),
            'icon' => $balance->icon(),
            'tone' => $balance->tone()->value,
            'count' => $count(fn (ReanalysisRow $row): bool => $row->balance === $balance),
        ], DemandBalance::cases());

        return [
            ...$chips,
            ['key' => 'new', 'label' => 'Nuevo ingreso', 'icon' => 'o-user-plus', 'tone' => 'neutral', 'count' => $count(fn (ReanalysisRow $row): bool => $row->balance === null)],
            ['key' => 'review', 'label' => 'Por revisar', 'icon' => 'o-exclamation-triangle', 'tone' => 'warning', 'count' => $count(fn (ReanalysisRow $row): bool => $row->needsReview || $row->insufficientHistory)],
        ];
    }

    /**
     * Las carreras del diff para el filtro de carrera, por clave.
     *
     * @param  list<ReanalysisRow>  $rows
     * @return list<array{id: string, name: string}>
     */
    private function careers(array $rows): array
    {
        $careers = [];

        foreach ($rows as $row) {
            $careers[$row->careerCode] = ['id' => $row->careerId, 'name' => "{$row->careerCode} · {$row->careerName}"];
        }

        ksort($careers);

        return array_values($careers);
    }

    /**
     * Lo que cambió desde la versión vigente, en el diff de SnapshotDiff y en una entrada por grupo para la lista «Lo que
     * ya cambiaste». Null si la carga no tiene versión con la que comparar.
     *
     * @return array{diff: array<string, mixed>, entries: list<array{kind: string, icon: string, subject: string, subjectName: string, group: string, campus: ?string, title: string, detail: string}>}|null
     */
    private function changesOf(AcademicLoad $load): ?array
    {
        try {
            $diff = $this->changes->since($load);
        } catch (LogicException) {
            return null;
        }

        $entries = [];

        foreach ($diff['groups']['added'] as $group) {
            $entries[] = $this->entry('added', 'o-plus-circle', $group, 'Grupo nuevo', $group['sessions'] === [] ? 'Sin horario' : SnapshotDiffText::sessions($group['sessions']));
        }

        foreach ($diff['groups']['cancelled'] as $group) {
            $entries[] = $this->entry('cancelled', 'o-archive-box-x-mark', $group, 'Grupo cancelado', 'Ya no se ofrece en la versión nueva.');
        }

        foreach ($diff['groups']['changed'] as $changed) {
            $entries[] = $this->entry('changed', 'o-pencil-square', $changed['group'], $this->shortChange($changed['changes']), Str::ucfirst(SnapshotDiffText::changes($changed['changes'])).'.');
        }

        return ['diff' => $diff, 'entries' => $entries];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{kind: string, icon: string, subject: string, subjectName: string, group: string, campus: ?string, title: string, detail: string}
     */
    private function entry(string $kind, string $icon, array $group, string $title, string $detail): array
    {
        return [
            'kind' => $kind,
            'icon' => $icon,
            'subject' => $group['subject']['code'],
            'subjectName' => $group['subject']['name'],
            'group' => $group['code'],
            'campus' => $group['campus'],
            'title' => $title,
            'detail' => $detail,
        ];
    }

    /**
     * Lo que ya se ajustó en cada materia y campus (llave materia|clave del campus), una frase corta por grupo: «E: grupo
     * nuevo», «D: grupo cancelado», «C: cupo 35 → 45».
     *
     * @param  array<string, mixed>|null  $diff
     * @return array<string, list<string>>
     */
    private function touchedByBlock(?array $diff): array
    {
        if ($diff === null) {
            return [];
        }

        $touched = [];
        $add = function (array $group, string $what) use (&$touched): void {
            $touched[$group['subject']['id'].'|'.($group['campus'] ?? '')][] = "{$group['code']}: {$what}";
        };

        foreach ($diff['groups']['added'] as $group) {
            $add($group, 'grupo nuevo');
        }

        foreach ($diff['groups']['cancelled'] as $group) {
            $add($group, 'grupo cancelado');
        }

        foreach ($diff['groups']['changed'] as $changed) {
            $add($changed['group'], mb_strtolower($this->shortChange($changed['changes'])));
        }

        return $touched;
    }

    /**
     * Lo que cambió en un grupo, corto: «Cupo 35 → 45», «Horario y salón», «Docente».
     *
     * @param  array<string, mixed>  $changes
     */
    private function shortChange(array $changes): string
    {
        $parts = [];

        if (isset($changes['quota'])) {
            $parts[] = "cupo {$changes['quota']['from']} → {$changes['quota']['to']}";
        }

        if (isset($changes['career_quotas'])) {
            $parts[] = 'lugares por carrera';
        }

        if (isset($changes['schedule'])) {
            $parts[] = 'horario';
        }

        if (isset($changes['room'])) {
            $parts[] = 'salón';
        }

        if (array_key_exists('teacher', $changes)) {
            $parts[] = 'docente';
        }

        $last = array_pop($parts);

        return Str::ucfirst($parts === [] ? (string) $last : implode(', ', $parts).' y '.$last);
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }
}
