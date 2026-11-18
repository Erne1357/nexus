<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\TermCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * El estado de una carga para la parte de arriba de «Re-análisis de la carga» (tarea 8.2, pantalla; RA-02, RA-10): la
 * línea del ciclo, las condiciones de RA-02 (de ReanalysisAvailabilityQuery, por condición), el avance de las
 * calificaciones del semestre actual (N de M) y quién inició el re-análisis. Solo lee.
 */
final class ReanalysisStateQuery
{
    public function __construct(
        private readonly ReanalysisAvailabilityQuery $availability,
        private readonly ReanalysisCycleQuery $cycle,
    ) {}

    public function of(AcademicLoad $load): ReanalysisState
    {
        $load->loadMissing(['term', 'modality', 'department']);
        $stage = $this->stageOf($load);
        $previous = TermCode::previousRegular($load->term->code);
        $gradesTerm = Term::query()->where('code', $previous)->value('name') ?? $previous;
        $kardexAt = $load->published_at === null ? null : $this->availability->lastKardexBatchAfter($load->published_at);
        $blockers = $stage === 'published' ? $this->availability->blockers($load) : [];
        $progress = $stage === 'other' ? ['graded' => 0, 'total' => 0, 'percent' => 0] : $this->availability->progress($load);
        $start = in_array($stage, ['reanalysis', 'closing'], true) ? $this->start($load) : null;

        return new ReanalysisState(
            load: $load,
            stage: $stage,
            steps: $stage === 'other' ? [] : $this->steps($load, $stage, $blockers, $progress, $kardexAt, $start['at'] ?? null),
            checks: $stage === 'published' ? $this->checks($load, $blockers, $progress, $gradesTerm, $kardexAt) : [],
            blockers: $blockers,
            progress: $progress,
            gradesTerm: $gradesTerm,
            startedAt: $start['at'] ?? null,
            startedBy: $start['by'] ?? null,
            publishedGroups: $stage === 'published' ? $this->publishedGroups($load) : null,
        );
    }

    /**
     * @return 'published'|'reanalysis'|'closing'|'other'
     */
    private function stageOf(AcademicLoad $load): string
    {
        return match (true) {
            $load->status === LoadStatus::Published => 'published',
            $load->status === LoadStatus::Reanalysis => 'reanalysis',
            in_array($load->status, [LoadStatus::Closed, LoadStatus::Approved], true) && $this->cycle->closedFromReanalysis($load) => 'closing',
            default => 'other',
        };
    }

    /**
     * La línea del ciclo de re-análisis de la versión vigente.
     *
     * @param  array<string, string>  $blockers
     * @param  array{graded: int, total: int, percent: int}  $progress
     * @return list<array{key: string, label: string, date: ?string, detail: ?string, state: 'done'|'current'|'ready'|'wait'|'next'}>
     */
    private function steps(AcademicLoad $load, string $stage, array $blockers, array $progress, ?CarbonImmutable $kardexAt, ?CarbonImmutable $startedAt): array
    {
        $captured = $progress['total'] > 0 ? "{$progress['percent']} % capturadas" : null;
        $gradesState = match (true) {
            $stage !== 'published', $blockers === [] => 'done',
            array_keys($blockers) === ['forecast'] => 'wait',
            default => 'next',
        };
        $gradesDetail = match ($gradesState) {
            'wait' => 'Falta la proyección',
            'next' => isset($blockers['kardex']) ? 'Sin kárdex nuevo' : 'Sin calificaciones nuevas',
            default => $captured,
        };

        return [
            ['key' => 'published', 'label' => "Publicada · v{$load->current_version}", 'date' => $this->day($load->published_at), 'detail' => null, 'state' => 'done'],
            ['key' => 'grades', 'label' => 'Calificaciones', 'date' => $this->day($kardexAt), 'detail' => $gradesDetail, 'state' => $gradesState],
            ['key' => 'reanalysis', 'label' => 'Re-análisis', 'date' => $this->day($startedAt), 'detail' => null, 'state' => match ($stage) {
                'reanalysis' => 'current',
                'closing' => 'done',
                default => $blockers === [] ? 'ready' : 'next',
            }],
            ['key' => 'closing', 'label' => 'Cierre', 'date' => $stage === 'closing' ? $this->day($load->closed_at) : null, 'detail' => $load->status === LoadStatus::Approved ? 'Con visto bueno' : null, 'state' => match (true) {
                $load->status === LoadStatus::Closed => 'current',
                $load->status === LoadStatus::Approved => 'done',
                default => 'next',
            }],
            ['key' => 'publish', 'label' => 'Publicar v'.($load->current_version + 1), 'date' => null, 'detail' => null, 'state' => $load->status === LoadStatus::Approved ? 'ready' : 'next'],
        ];
    }

    /**
     * Las tres condiciones de RA-02 con su detalle: el kárdex después de la publicación, las calificaciones nuevas del
     * semestre actual (con su avance) y la proyección recalculada.
     *
     * @param  array<string, string>  $blockers
     * @param  array{graded: int, total: int, percent: int}  $progress
     * @return list<array{key: string, title: string, detail: string, state: 'ok'|'wait'|'pending'}>
     */
    private function checks(AcademicLoad $load, array $blockers, array $progress, string $gradesTerm, ?CarbonImmutable $kardexAt): array
    {
        $kardexMissing = isset($blockers['load']) || isset($blockers['kardex']);
        $forecastAt = DemandForecast::query()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->max('computed_at');
        $captured = number_format($progress['graded']).' de '.number_format($progress['total'])." capturadas ({$progress['percent']} %). Si llegan más después, puedes volver a re-analizar.";

        return [
            [
                'key' => 'kardex',
                'title' => 'Kárdex importado después de la publicación',
                'detail' => $kardexMissing ? ($blockers['load'] ?? $blockers['kardex']) : 'Aplicado el '.$this->moment($kardexAt),
                'state' => $kardexMissing ? 'wait' : 'ok',
            ],
            [
                'key' => 'grades',
                'title' => "Calificaciones nuevas de {$gradesTerm}",
                'detail' => $kardexMissing ? 'Se revisa cuando llegue el kárdex.' : ($blockers['grades'] ?? $captured),
                'state' => $kardexMissing ? 'pending' : (isset($blockers['grades']) ? 'wait' : 'ok'),
            ],
            [
                'key' => 'forecast',
                'title' => 'Proyección recalculada',
                'detail' => match (true) {
                    $kardexMissing => 'Se revisa cuando llegue el kárdex.',
                    isset($blockers['forecast']) => $blockers['forecast'].' Suele tardar unos minutos; esta página se pone al día sola.',
                    default => 'Recalculada el '.$this->moment($forecastAt === null ? null : new CarbonImmutable($forecastAt)).', con las calificaciones importadas.',
                },
                'state' => $kardexMissing ? 'pending' : (isset($blockers['forecast']) ? 'wait' : 'ok'),
            ],
        ];
    }

    /**
     * Cuándo y quién inició el re-análisis en curso: la última transición a `reanalysis`.
     *
     * @return array{at: CarbonImmutable, by: ?string}|null
     */
    private function start(AcademicLoad $load): ?array
    {
        $transition = LoadTransition::query()
            ->where('academic_load_id', $load->getKey())
            ->where('to_status', LoadStatus::Reanalysis)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $transition === null ? null : ['at' => CarbonImmutable::parse($transition->created_at), 'by' => $transition->user?->name];
    }

    /**
     * Cuántos grupos tiene la versión vigente, sin leer toda la foto.
     */
    private function publishedGroups(AcademicLoad $load): ?int
    {
        $count = LoadSnapshot::query()
            ->where('academic_load_id', $load->getKey())
            ->where('version', $load->current_version)
            ->selectRaw("jsonb_array_length(payload->'groups') as total")
            ->value('total');

        return $count === null ? null : (int) $count;
    }

    private function day(?CarbonInterface $at): ?string
    {
        return $at?->timezone(config('app.timezone'))->format('d/m');
    }

    private function moment(?CarbonInterface $at): string
    {
        return $at === null ? '—' : $at->timezone(config('app.timezone'))->translatedFormat('j M Y').' a las '.$at->timezone(config('app.timezone'))->format('G:i');
    }
}
