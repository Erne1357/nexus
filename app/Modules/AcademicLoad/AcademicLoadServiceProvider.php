<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
use App\Modules\AcademicLoad\Console\EvaluateForecastCommand;
use App\Modules\AcademicLoad\Console\ImportSiiGroups;
use App\Modules\AcademicLoad\Console\PruneExports;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\ForecastEvaluationDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\HistoricalGroupsDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\ProposalForecastDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\PublicationDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\BlockReviewReopeningAfterDraft;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\AcademicLoad\Listeners\NotifyAffectedOnRepublication;
use App\Modules\AcademicLoad\Listeners\NotifyCoordinatorsOnProposalReturned;
use App\Modules\AcademicLoad\Listeners\NotifyDepartmentsOnProposalSubmitted;
use App\Modules\AcademicLoad\Listeners\NotifyOnLoadClosingTransition;
use App\Modules\AcademicLoad\Listeners\NotifyReanalysisAvailableOnForecastsComputed;
use App\Modules\AcademicLoad\Listeners\PreviewLoadsOnTermOpening;
use App\Modules\AcademicLoad\Listeners\RecordGroupHistoryOnPublication;
use App\Modules\AcademicLoad\Listeners\ReportLoadStatuses;
use App\Modules\AcademicLoad\Listeners\ReportUnpublishedLoadsOnModalityDeactivating;
use App\Modules\AcademicLoad\Listeners\SyncProposalsOnForecastsComputed;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Notifications\ReanalysisAvailable;
use App\Modules\AcademicLoad\Policies\DemandBoardPolicy;
use App\Modules\AcademicLoad\Policies\ForecastEvaluationPolicy;
use App\Modules\AcademicLoad\Policies\PublishedExportPolicy;
use App\Modules\AcademicLoad\Queries\DemandBoard;
use App\Modules\AcademicLoad\Queries\LoadProgressQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\AcademicLoad\Queries\TermKardexQuery;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\ModalityDeactivating;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Events\TermOpeningPreviewing;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Demand\Events\DemandForecastsComputed;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Students\Models\KardexRecord;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Módulo AcademicLoad, el orquestador: la carga académica de cada departamento en una modalidad y un periodo (M1)
 * (docs/dominio/modelo-de-datos.md, "AcademicLoad"). Depende de Academic, CatalogValidation y Demand, y de lo
 * que está a su izquierda.
 *
 * Escucha a Core, a Academic y a CatalogValidation, que no dependen de AcademicLoad: al abrir un periodo en una
 * modalidad crea las cargas que falten, veta borrar un departamento que tiene cargas y veta reabrir una revisión
 * de catálogo si una carga del departamento ya salió de borrador, y le dice al tablero de avance de validación
 * el estado de cada carga (LoadStatusesRequested).
 */
final class AcademicLoadServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [
        PublishedExport::class => PublishedExportPolicy::class,
        ForecastEvaluation::class => ForecastEvaluationPolicy::class,
        DemandBoard::class => DemandBoardPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateLoadsOnTermOpened::class);
        Event::listen(ModalityDeactivating::class, ReportUnpublishedLoadsOnModalityDeactivating::class);
        Event::listen(TermOpeningPreviewing::class, PreviewLoadsOnTermOpening::class);
        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithLoads::class);
        Event::listen(CatalogReviewReopening::class, BlockReviewReopeningAfterDraft::class);
        Event::listen(LoadStatusesRequested::class, ReportLoadStatuses::class);
        Event::listen(CareerProposalSubmitted::class, NotifyDepartmentsOnProposalSubmitted::class);
        Event::listen(CareerProposalReturned::class, NotifyCoordinatorsOnProposalReturned::class);
        Event::listen(LoadTransitioned::class, NotifyOnLoadClosingTransition::class);
        Event::listen(DemandForecastsComputed::class, SyncProposalsOnForecastsComputed::class);
        Event::listen(DemandForecastsComputed::class, NotifyReanalysisAvailableOnForecastsComputed::class);
        Event::listen(LoadTransitioned::class, RecordGroupHistoryOnPublication::class);
        Event::listen(LoadTransitioned::class, NotifyAffectedOnRepublication::class);

        RateLimiter::for('published-schedule', fn (Request $request): Limit => Limit::perMinute(PublishedThrottle::PER_MINUTE)->by(PublishedThrottle::who($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneExports::class, ImportSiiGroups::class, EvaluateForecastCommand::class]);
        }
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        // MN-02: la misma closure en todos los módulos que la usan, así el menú consulta una vez.
        $loadInProgress = LoadProgressQuery::menuCondition();

        return [
            new NavigationItem('Propuesta de carrera', 'academic-load.proposal', 'o-clipboard-document-list', NavigationArea::Academico, group: NavigationGroup::Propuesta, whyUnavailable: $loadInProgress,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalView)),
            // «Paralelos» (Scheduling, tarea 8.8) es una pestaña de la bandeja: va como su pantalla hija, sin entrada propia.
            new NavigationItem('Bandeja del departamento', 'academic-load.inbox', 'o-inbox-arrow-down', NavigationArea::Academico, group: NavigationGroup::Propuesta, whyUnavailable: $loadInProgress,
                childRoutes: ['scheduling.parallels'],
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalReviewView)),
            // Evalúa periodos ya cursados: no depende de que haya carga en curso (MN-04 no la nombra; supuesto por confirmar).
            new NavigationItem('Precisión de la proyección', 'academic-load.forecast-accuracy', 'o-scale', NavigationArea::Academico, group: NavigationGroup::Propuesta, order: 1,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ForecastEvaluationView)),
            new NavigationItem('Demanda por materia', 'academic-load.demand', 'o-chart-bar', NavigationArea::Academico, group: NavigationGroup::Propuesta, whyUnavailable: $loadInProgress,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::DemandViewDepartment) || $context->allowsAny(AcademicLoadPermission::DemandViewCareer)),
            // Después del cierre y del re-análisis («Re-análisis de la carga», Scheduling, orden 1) y antes de la consulta
            // publicada: es la trazabilidad de lo publicado (tarea 8.10). Como Horarios publicados, no depende de que haya
            // carga en curso: las versiones siguen ahí al cerrar el periodo.
            new NavigationItem('Versiones publicadas', 'academic-load.versions', 'o-rectangle-stack', NavigationArea::Academico, group: NavigationGroup::CierrePublicacion, order: 2,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::LoadView)),
            // Al final de «Cierre y publicación» (MN-01).
            new NavigationItem('Horarios publicados', 'published.index', 'o-calendar-days', NavigationArea::Academico, group: NavigationGroup::CierrePublicacion, order: 3,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::LoadView) || $context->allowsAny(AcademicLoadPermission::ProposalView)),
        ];
    }

    public function permissions(): array
    {
        return [AcademicLoadPermission::class];
    }

    /**
     * Subdirección y el jefe de proyecto docente ven la carga y la bandeja (Subdirección, además, la propuesta, y
     * cierra, reabre, da el visto bueno y publica las cargas, tareas 4.1 a 4.3: cierra cualquiera sin ser paso
     * obligatorio, confirmado el 10 de noviembre de 2026); el jefe de departamento, además, propone la carga, la pasa a
     * asignación docente, la cierra, inicia el re-análisis de una carga publicada (fase 8, RA-01), la publica si la modalidad no exige visto bueno, decide la parte de su departamento y
     * pide, ve y decide acuerdos entre departamentos (Subdirección y el jefe de proyecto docente solo los ven); el
     * coordinador arma, corrige, envía y ve la propuesta de su carrera. «Demanda por materia» (tarea 5.7, confirmado el 16
     * de noviembre de 2026) la ven el jefe y el jefe de proyecto docente de las materias que imparte su departamento,
     * Subdirección de cualquier departamento, y el coordinador de las materias de su carrera, en todos los departamentos.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadExport,
                AcademicLoadPermission::LoadExportSii,
                AcademicLoadPermission::LoadClose,
                AcademicLoadPermission::LoadReopen,
                AcademicLoadPermission::LoadApprove,
                AcademicLoadPermission::LoadPublish,
                AcademicLoadPermission::LoadPublishApproved,
                AcademicLoadPermission::ProposalView,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::AgreementView,
                AcademicLoadPermission::DemandViewDepartment,
                AcademicLoadPermission::ForecastEvaluationView,
                AcademicLoadPermission::ForecastEvaluationRun,
            ],
            Role::JefeDepartamento->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadExport,
                AcademicLoadPermission::LoadExportSii,
                AcademicLoadPermission::LoadPropose,
                AcademicLoadPermission::LoadStartStaffing,
                AcademicLoadPermission::LoadClose,
                AcademicLoadPermission::LoadStartReanalysis,
                AcademicLoadPermission::LoadPublish,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::ProposalReviewDecide,
                AcademicLoadPermission::AgreementView,
                AcademicLoadPermission::AgreementRequest,
                AcademicLoadPermission::AgreementDecide,
                AcademicLoadPermission::DemandViewDepartment,
            ],
            Role::JefeProyectoDocente->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadExport,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::AgreementView,
                AcademicLoadPermission::DemandViewDepartment,
            ],
            Role::Coordinador->value => [
                AcademicLoadPermission::ProposalCreate,
                AcademicLoadPermission::ProposalUpdate,
                AcademicLoadPermission::ProposalSubmit,
                AcademicLoadPermission::ProposalView,
                AcademicLoadPermission::DemandViewCareer,
            ],
        ];
    }

    /**
     * Demo por puntos: al llegar a «propuesta», las propuestas precargadas con todos los casos de la fase 5 (#520,
     * ProposalForecastDemoSeeder): sin propuesta, en borrador sin tocar y con correcciones, filas cambiadas por un recálculo,
     * paquetes, enviadas, regresadas y con partes aceptadas. Después, cada carrera ofrecida en los campus del volumen
     * propone y envía, y cada departamento acepta todas sus partes (AcademicLoadDemoSeeder en modo completo, que termina lo
     * que dejó «propuesta»); así nacen los grupos y las cargas con partes pasan a propuesta, lo que lleva a «horarios».
     * Necesita los catálogos validados del punto anterior (HC-14).
     */
    public function demoSteps(): array
    {
        return [
            // Después del kárdex de Students (que lleva a «catálogos»): el historial se arma con sus alumnos por materia y campus.
            new DemoStep(DemoCheckpoint::Catalogs, 'Historial de grupos por materia, coherente con el kárdex', fn (DemoContext $demo) => $demo->runSeeder(HistoricalGroupsDemoSeeder::class, ['seed' => $demo->seed])),
            // Después de abrir el periodo (Core), que calcula la proyección de Demand; el kárdex ya está sembrado (tarea 5.10a).
            new DemoStep(DemoCheckpoint::Proposal, 'Evaluaciones de la precisión de la proyección', fn (DemoContext $demo) => $demo->runSeederPerModality(ForecastEvaluationDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
            ])),
            // Después de los catálogos validados (CatalogValidation) y de la proyección (Demand), que van antes en el mapa.
            // Las modalidades juntas: el recálculo de la proyección es del kárdex y corre una sola vez.
            new DemoStep(DemoCheckpoint::Proposal, 'Propuestas precargadas con todos los casos', fn (DemoContext $demo) => $demo->runSeeder(ProposalForecastDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
                'seed' => $demo->seed,
                'modalities' => $demo->volume->modalities(),
            ])),
            new DemoStep(DemoCheckpoint::Scheduling, 'Propuestas de carrera enviadas y aceptadas', fn (DemoContext $demo) => $demo->runSeederPerModality(AcademicLoadDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
                'seed' => $demo->seed,
                'complete' => true,
            ])),
            // La publicación termina la etapa 8: cada carga cerrada sale con su versión 1 (tarea 4.3).
            new DemoStep(DemoCheckpoint::Reanalysis, 'Cargas cerradas publicadas con su versión 1', fn (DemoContext $demo) => $demo->runSeederPerModality(PublicationDemoSeeder::class, [
                'term' => $demo->termCode,
            ])),
            // Los casos del re-análisis (fase 8, #614), sobre lo publicado: primero llegan las calificaciones y se recalcula la
            // proyección; luego los jefes inician sus re-análisis. Scheduling y Staffing siguen con los ajustes y las versiones.
            new DemoStep(DemoCheckpoint::ReanalysisCases, 'Calificaciones del semestre anterior al 85 % con su lote de kárdex, y la proyección recalculada', fn (DemoContext $demo) => $demo->runSeeder(ReanalysisDemoSeeder::class, [
                'mode' => 'grades',
                'term' => $demo->termCode,
                'seed' => $demo->seed,
            ])),
            new DemoStep(DemoCheckpoint::ReanalysisCases, 'Re-análisis iniciados por los jefes, uno cancelado y vuelto a iniciar', fn (DemoContext $demo) => $demo->runSeederPerModality(ReanalysisDemoSeeder::class, [
                'mode' => 'start',
                'term' => $demo->termCode,
            ])),
        ];
    }

    public function demoChecks(): array
    {
        $campusIds = fn (DemoContext $demo): array => Campus::query()->whereIn('code', $demo->campuses)->pluck('id')->all();
        $modalityIds = fn (DemoContext $demo): array => Modality::query()->whereIn('code', $demo->volume->modalities())->pluck('id')->all();
        // Las carreras ofrecidas en los campus de la demo, cada una una vez: su propuesta es una por modalidad (M6).
        $offeredCareerIds = fn (DemoContext $demo): array => CareerCampus::query()->whereIn('campus_id', $campusIds($demo))->where('is_active', true)->distinct()->pluck('career_id')->all();
        $proposals = fn (DemoContext $demo): Builder => CareerProposal::query()->where('term_id', $demo->term()->getKey())
            ->whereIn('modality_id', $modalityIds($demo))->whereIn('career_id', $offeredCareerIds($demo));

        $evaluations = fn (DemoContext $demo, ForecastEvaluationStatus $status = ForecastEvaluationStatus::Done): Builder => ForecastEvaluation::query()
            ->where('status', $status)->whereIn('modality_id', $modalityIds($demo))
            ->whereIn('term_id', Term::query()->where('code', '<', $demo->termCode)->select('id'))
            ->where(fn (Builder $scope) => $scope->whereNull('campus_id')->orWhereIn('campus_id', $campusIds($demo)));
        // Sin kárdex con historia (un periodo ordinario anterior con uno previo del mismo tipo) no hay qué evaluar.
        $evaluable = function (DemoContext $demo) use ($modalityIds): bool {
            foreach (Modality::query()->whereIn('id', $modalityIds($demo))->get() as $modality) {
                $codes = collect(app(TermKardexQuery::class)->termCodes($modality))->filter(fn (string $code): bool => $code < $demo->termCode && TermCode::isRegular($code));

                if ($codes->contains(fn (string $code): bool => $codes->contains(fn (string $other): bool => $other < $code && substr($other, -1) === substr($code, -1)))) {
                    return true;
                }
            }

            return false;
        };

        return [
            ...$this->proposalCaseChecks($campusIds, $offeredCareerIds, $modalityIds, $proposals),
            new DemoCheck([DemoCheckpoint::Catalogs], 'Hay historial de grupos por materia', fn (): true|string => HistoricalGroup::query()->exists() || ! KardexRecord::query()->exists() ? true : 'Hay kárdex, pero no hay historial de grupos.'),
            new DemoCheck([DemoCheckpoint::Proposal], 'Hay evaluaciones de la precisión listas de los periodos con historia', fn (DemoContext $demo): true|string => $evaluations($demo)->exists() === false && $evaluable($demo)
                ? 'No hay evaluaciones de la proyección listas.'
                : true),
            new DemoCheck([DemoCheckpoint::Proposal], 'Hay una evaluación fallida para ver el estado de error', fn (DemoContext $demo): true|string => $evaluations($demo, ForecastEvaluationStatus::Failed)->exists() === false && $evaluable($demo)
                ? 'No hay una evaluación fallida.'
                : true),
            new DemoCheck([DemoCheckpoint::Reanalysis], 'Toda carga que salió de borrador está publicada con su versión 1', function (DemoContext $demo) use ($modalityIds): true|string {
                $behind = AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('modality_id', $modalityIds($demo))
                    ->where('status', '!=', LoadStatus::Draft)
                    ->where(fn (Builder $load) => $load->where('status', '!=', LoadStatus::Published)->orWhere('current_version', '!=', 1)
                        ->orWhereDoesntHave('snapshots', fn (Builder $snapshot) => $snapshot->where('version', 1)))
                    ->count();

                return $behind === 0 ? true : "{$behind} cargas con grupos no están publicadas en su versión 1.";
            }),
            new DemoCheck([DemoCheckpoint::Reanalysis], 'Cada foto cumple la forma del snapshot y el historial docente la sigue', function (DemoContext $demo) use ($campusIds, $modalityIds): true|string {
                $snapshots = LoadSnapshot::query()->latestVersion()
                    ->whereIn('academic_load_id', AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('modality_id', $modalityIds($demo))->select('id'))
                    ->get();
                $invalid = $snapshots->filter(fn (LoadSnapshot $snapshot): bool => SnapshotPayload::errors(SnapshotPayload::normalize($snapshot->payload)) !== [])->count();
                $taught = $snapshots->sum(fn (LoadSnapshot $snapshot): int => count(array_filter(
                    SnapshotPayload::normalize($snapshot->payload)['groups'],
                    fn (array $group): bool => $group['teacher'] !== null && in_array($group['campus']['code'] ?? null, $demo->campuses, true),
                )));
                $history = TeacherSubjectHistory::query()->where('term_code', $demo->termCode)->whereIn('campus_id', $campusIds($demo))->where('source', HistorySource::Nexus)->count();

                return match (true) {
                    $snapshots->isEmpty() => 'No hay fotos publicadas.',
                    $invalid > 0 => "{$invalid} fotos no cumplen la forma del snapshot.",
                    $history !== $taught => "El historial docente tiene {$history} filas de Nexus y las fotos dicen {$taught} grupos con docente.",
                    default => true,
                };
            }),
            ...$this->reanalysisCaseChecks(),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Scheduling), 'Cada carrera ofrecida tiene su propuesta aceptada', function (DemoContext $demo) use ($offeredCareerIds, $modalityIds, $proposals): true|string {
                $offered = count($offeredCareerIds($demo)) * count($modalityIds($demo));
                $accepted = $proposals($demo)->where('status', ProposalStatus::Accepted)->count();

                return $accepted === $offered ? true : "{$accepted} de {$offered} carreras ofrecidas tienen su propuesta aceptada.";
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Scheduling), 'Ninguna parte de propuesta pendiente ni regresada', function (DemoContext $demo) use ($proposals): true|string {
                $open = ProposalReview::query()->whereIn('career_proposal_id', $proposals($demo)->select('id'))->where('status', '!=', ProposalReviewStatus::Accepted)->count();

                return $open === 0 ? true : "{$open} partes siguen sin aceptar.";
            }),
            // Una carga se compara con las partes de su misma modalidad (M1, M6), de las carreras de los campus de la demo: un
            // departamento sin partes aceptadas deja su carga en borrador.
            new DemoCheck([DemoCheckpoint::Scheduling], 'Las cargas con partes aceptadas están en propuesta', function (DemoContext $demo) use ($offeredCareerIds, $modalityIds): true|string {
                $behind = AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('modality_id', $modalityIds($demo))
                    ->where('status', '!=', LoadStatus::Proposed)
                    ->whereExists(fn (QueryBuilder $parts) => $parts->selectRaw('1')->from('proposal_reviews')
                        ->join('career_proposals', 'career_proposals.id', '=', 'proposal_reviews.career_proposal_id')
                        ->whereColumn('proposal_reviews.department_id', 'academic_loads.department_id')
                        ->whereColumn('career_proposals.modality_id', 'academic_loads.modality_id')
                        ->whereIn('career_proposals.career_id', $offeredCareerIds($demo))
                        ->whereColumn('career_proposals.term_id', 'academic_loads.term_id'))
                    ->count();

                return $behind === 0 ? true : "{$behind} cargas con partes aceptadas no están en propuesta.";
            }),
        ];
    }

    /**
     * Una revisión por caso del re-análisis en `reanalisis-casos` (fase 8, #614), sobre las cargas que el plan de
     * ReanalysisDemoSeeder le da a cada caso en cada modalidad del volumen. Un caso que el plan no reparte (con pocas
     * cargas: el volumen demo tiene cuatro, sin «cerrada» ni «republicada») no tiene qué revisar y pasa.
     *
     * @return list<DemoCheck>
     */
    private function reanalysisCaseChecks(): array
    {
        $at = [DemoCheckpoint::ReanalysisCases];
        $loads = function (DemoContext $demo, string ...$cases): Collection {
            $loads = new Collection;

            foreach (Modality::query()->whereIn('code', $demo->volume->modalities())->orderBy('code')->get() as $modality) {
                $loads = $loads->concat(ReanalysisDemoSeeder::loadsIn($demo->term()->getKey(), $modality->getKey(), $cases));
            }

            return $loads->values();
        };
        $codes = fn (Collection $loads): string => $loads->map(fn (AcademicLoad $load): string => $load->department->code)->implode(', ');
        $reasons = fn (AcademicLoad $load): array => app(ReanalysisAvailabilityQuery::class)->reasons($load);
        $hasChanges = fn (AcademicLoad $load): bool => app(ReanalysisChangesQuery::class)->hasChanges($load);
        $versions = fn (AcademicLoad $load): array => $load->snapshots()->orderBy('version')->pluck('reason', 'version')->map(fn (?SnapshotReason $reason): ?string => $reason?->value)->all();
        // Los avisos de la republicación: los del docente no llevan puesto (`data.role`); los de los coordinadores y los de los
        // jefes de otros departamentos llegan con el suyo (NotifyHolders), dos puestos distintos.
        $notices = fn (): array => DB::table('notifications')->where('type', LoadRepublished::class)
            ->selectRaw("coalesce(data::jsonb ->> 'role', '') as role, count(*) as total")->groupByRaw("coalesce(data::jsonb ->> 'role', '')")
            ->pluck('total', 'role')->all();

        return [
            new DemoCheck($at, 'Las calificaciones del semestre anterior van entre 80 y 90 % capturadas, con su lote de kárdex después de la publicación', function (DemoContext $demo) use ($loads): true|string {
                $load = $loads($demo, ...ReanalysisDemoSeeder::CASES)->first();

                if ($load === null) {
                    return true;
                }

                $progress = app(ReanalysisAvailabilityQuery::class)->progress($load);
                $batch = ImportBatch::query()->where('template', 'kardex')->where('status', BatchStatus::Applied->value)
                    ->where('original_name', ReanalysisDemoSeeder::batchName(TermCode::previousRegular($demo->termCode)))->exists();

                return match (true) {
                    ! $batch => 'No hay lote de kárdex con las calificaciones del semestre anterior.',
                    $progress['percent'] < 80 || $progress['percent'] > 90 => "Van {$progress['graded']} de {$progress['total']} calificaciones ({$progress['percent']} %).",
                    default => true,
                };
            }),
            new DemoCheck($at, 'Hay cargas con el re-análisis habilitado sin iniciar, y su jefe tiene el aviso', function (DemoContext $demo) use ($loads, $codes): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_AVAILABLE)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Published
                    && app(ReanalysisAvailabilityQuery::class)->isAvailable($load)
                    && DB::table('notifications')->where('type', ReanalysisAvailable::class)
                        ->whereRaw("data::jsonb -> 'parameters' ->> 'departamento' = ?", [$load->department_id])
                        ->whereRaw("data::jsonb ->> 'term_id' = ?", [$load->term_id])
                        ->exists());

                return $behind->isEmpty() ? true : "Sin el re-análisis habilitado o sin el aviso a su jefe: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay cargas publicadas sin re-análisis habilitado, con su motivo', function (DemoContext $demo) use ($loads, $reasons): true|string {
                $all = $loads($demo, ...ReanalysisDemoSeeder::CASES);
                $blocked = $all->filter(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Published && $reasons($load) !== []);

                return $all->isEmpty() || $blocked->isNotEmpty() ? true : 'Toda carga publicada tiene el re-análisis habilitado.';
            }),
            new DemoCheck($at, 'Hay cargas en re-análisis sin cambios, que se pueden cancelar', function (DemoContext $demo) use ($loads, $codes, $hasChanges): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_RESTARTED, ReanalysisDemoSeeder::CASE_UNCHANGED)
                    ->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Reanalysis && ! $hasChanges($load));

                return $behind->isEmpty() ? true : "No están en re-análisis sin cambios: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay cargas que cancelaron su re-análisis y lo volvieron a iniciar', function (DemoContext $demo) use ($loads, $codes): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_RESTARTED)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Reanalysis
                    && $load->transitions()->where('comment', CancelReanalysis::COMMENT)->exists());

                return $behind->isEmpty() ? true : "No cancelaron y volvieron a iniciar su re-análisis: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay cargas en re-análisis con cambios, que ya no se cancelan, devueltas por Subdirección (RA-08)', function (DemoContext $demo) use ($loads, $codes, $hasChanges): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_CHANGED)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Reanalysis && $hasChanges($load)
                    && $load->transitions()->where('from_status', LoadStatus::Closed->value)->where('to_status', LoadStatus::Reanalysis->value)->exists());

                return $behind->isEmpty() ? true : "No están en re-análisis con cambios y devueltas: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay cargas re-analizadas y cerradas, por publicar su versión siguiente', function (DemoContext $demo) use ($loads, $codes): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_CLOSED)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Closed
                    && app(ReanalysisCycleQuery::class)->closedFromReanalysis($load));

                return $behind->isEmpty() ? true : "No están cerradas después de su re-análisis: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay versiones 2 por re-análisis con su diff guardado y cada tipo de cambio', function (DemoContext $demo) use ($loads, $codes): true|string {
                $versioned = $loads($demo, ReanalysisDemoSeeder::CASE_REPUBLISHED, ReanalysisDemoSeeder::CASE_CORRECTED);

                if ($versioned->isEmpty()) {
                    return true;
                }

                $snapshots = LoadSnapshot::query()->whereIn('academic_load_id', $versioned->map(fn (AcademicLoad $load): string => $load->getKey())->all())->where('version', 2)->get();
                $missing = $versioned->reject(fn (AcademicLoad $load): bool => $snapshots->contains(fn (LoadSnapshot $snapshot): bool => $snapshot->academic_load_id === $load->getKey()
                    && $snapshot->reason === SnapshotReason::Reanalysis && $snapshot->diff !== null));
                $kinds = [];

                foreach ($snapshots as $snapshot) {
                    foreach (['added', 'cancelled', 'changed'] as $kind) {
                        $kinds[$kind] = ($kinds[$kind] ?? false) || ($snapshot->diff['groups'][$kind] ?? []) !== [];
                    }

                    foreach ($snapshot->diff['groups']['changed'] ?? [] as $changed) {
                        foreach (array_keys($changed['changes']) as $kind) {
                            $kinds[$kind] = true;
                        }
                    }
                }

                $absent = array_values(array_filter(['added', 'cancelled', 'changed', 'schedule', 'teacher', 'room', 'quota', 'career_quotas'], fn (string $kind): bool => ! ($kinds[$kind] ?? false)));

                return match (true) {
                    $missing->isNotEmpty() => "Sin versión 2 por re-análisis con diff: {$codes($missing)}.",
                    $absent !== [] => 'Las versiones 2 no traen: '.implode(', ', $absent).'.',
                    default => true,
                };
            }),
            new DemoCheck($at, 'Se avisó a docentes, coordinadores y jefes de otros departamentos al republicar', function (DemoContext $demo) use ($loads, $notices): true|string {
                if ($loads($demo, ReanalysisDemoSeeder::CASE_REPUBLISHED, ReanalysisDemoSeeder::CASE_CORRECTED)->isEmpty()) {
                    return true;
                }

                $sent = $notices();
                $teachers = (int) ($sent[''] ?? 0);
                $roles = count(array_filter(array_keys($sent), fn (int|string $role): bool => $role !== ''));

                return match (true) {
                    $teachers === 0 => 'Ningún aviso de republicación a los docentes.',
                    $roles < 2 => 'Los avisos de republicación no llegaron a coordinadores y a jefes de otros departamentos (puestos: '.$roles.').',
                    default => true,
                };
            }),
            new DemoCheck($at, 'Las cargas republicadas siguen en su versión 2 y esperan calificaciones nuevas', function (DemoContext $demo) use ($loads, $codes, $reasons, $versions): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_REPUBLISHED)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Published
                    && $versions($load) === [1 => 'published', 2 => 'reanalysis'] && $reasons($load) !== []);

                return $behind->isEmpty() ? true : "No están publicadas en su versión 2 sin re-análisis habilitado: {$codes($behind)}.";
            }),
            new DemoCheck($at, 'Hay cargas en su versión 3 por corrección después del re-análisis', function (DemoContext $demo) use ($loads, $codes, $versions): true|string {
                $behind = $loads($demo, ReanalysisDemoSeeder::CASE_CORRECTED)->reject(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Published
                    && $versions($load) === [1 => 'published', 2 => 'reanalysis', 3 => 'correction']);

                return $behind->isEmpty() ? true : "No llegaron a la versión 3 por corrección: {$codes($behind)}.";
            }),
        ];
    }

    /**
     * Las revisiones de «propuesta» (#520): cada caso de las pantallas de la fase 5 tiene al menos un ejemplo en las
     * propuestas de la demo, en sus campus (ProposalForecastDemoSeeder::count), y alguna carrera ofrecida se quedó sin
     * propuesta.
     *
     * @param  Closure(DemoContext): list<string>  $campusIds
     * @param  Closure(DemoContext): list<string>  $offeredCareerIds
     * @param  Closure(DemoContext): list<string>  $modalityIds
     * @param  Closure(DemoContext): Builder<CareerProposal>  $proposals
     * @return list<DemoCheck>
     */
    private function proposalCaseChecks(Closure $campusIds, Closure $offeredCareerIds, Closure $modalityIds, Closure $proposals): array
    {
        $cases = [
            ProposalForecastDemoSeeder::CASE_SUGGESTED_ROW => 'La propuesta trae filas sin tocar con lo sugerido de la proyección vigente',
            ProposalForecastDemoSeeder::CASE_CAPTURED_ROW => 'La propuesta trae filas corregidas',
            ProposalForecastDemoSeeder::CASE_CHANGED_ROW => 'La propuesta trae filas corregidas cuya proyección cambió',
            ProposalForecastDemoSeeder::CASE_REVIEWED_ROW => 'La propuesta trae filas revisadas',
            ProposalForecastDemoSeeder::CASE_PACKAGE => 'La propuesta trae paquetes de primer semestre que abren grupos',
            ProposalForecastDemoSeeder::CASE_CORRECTED_INTAKE => 'La propuesta trae un nuevo ingreso corregido',
            ProposalForecastDemoSeeder::CASE_DRAFTING => 'Hay propuestas en captura',
            ProposalForecastDemoSeeder::CASE_SUBMITTED => 'Hay propuestas enviadas',
            ProposalForecastDemoSeeder::CASE_RETURNED => 'Hay propuestas regresadas',
            ProposalForecastDemoSeeder::CASE_PARTIALLY_ACCEPTED => 'Hay propuestas con una parte aceptada',
        ];

        return [
            ...array_map(
                fn (string $case, string $label): DemoCheck => new DemoCheck([DemoCheckpoint::Proposal], $label,
                    fn (DemoContext $demo): true|string => ProposalForecastDemoSeeder::count($case, $proposals($demo), $campusIds($demo)) > 0 ? true : 'Ninguna propuesta de la demo trae el caso.'),
                array_keys($cases),
                array_values($cases),
            ),
            // Por cada modalidad abierta en el periodo, las carreras ofrecidas que no tienen su propuesta en ella.
            new DemoCheck([DemoCheckpoint::Proposal], 'Hay carreras ofrecidas sin propuesta', function (DemoContext $demo) use ($offeredCareerIds, $modalityIds, $proposals): true|string {
                $offered = $offeredCareerIds($demo);
                $open = TermOpening::query()->where('term_id', $demo->term()->getKey())->whereIn('modality_id', $modalityIds($demo))->pluck('modality_id');
                $missing = $open->sum(fn (string $modalityId): int => count($offered) - $proposals($demo)->where('modality_id', $modalityId)->count());

                return $missing > 0 ? true : 'Todas las carreras ofrecidas tienen propuesta.';
            }),
        ];
    }

    public function activityAttributes(): array
    {
        return [
            AcademicLoad::class => ['status' => ['Estado de la carga', LoadStatus::class], 'current_version' => 'Versión vigente', 'closed_at' => 'Cerrada', 'closed_by' => 'Cerrada por', 'approved_at' => 'Aprobada', 'approved_by' => 'Aprobada por', 'published_at' => 'Publicada', 'published_by' => 'Publicada por'],
            CareerProposal::class => ['status' => ['Estado de la propuesta', ProposalReviewStatus::class], 'submitted_at' => 'Enviada', 'submitted_by' => 'Enviada por'],
            ForecastEvaluation::class => ['status' => ['Estado de la evaluación', ForecastEvaluationStatus::class], 'method' => 'Versión de la fórmula', 'history_terms' => 'Semestres de historia'],
            InterdepartmentalAgreement::class => ['status' => ['Estado', AgreementStatus::class], 'kind' => ['Tipo de acuerdo', AgreementKind::class], 'max_seats' => 'Cupo máximo', 'from_department_id' => 'Departamento que da', 'to_department_id' => 'Departamento que recibe', 'cancelled_at' => 'Cancelado', 'cancelled_by' => 'Cancelado por'],
        ];
    }
}
