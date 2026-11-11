<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Console\PruneExports;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\PublicationDemoSeeder;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\BlockReviewReopeningAfterDraft;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\AcademicLoad\Listeners\NotifyCoordinatorsOnProposalReturned;
use App\Modules\AcademicLoad\Listeners\NotifyDepartmentsOnProposalSubmitted;
use App\Modules\AcademicLoad\Listeners\NotifyOnLoadClosingTransition;
use App\Modules\AcademicLoad\Listeners\ReportLoadStatuses;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Policies\PublishedExportPolicy;
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
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Módulo AcademicLoad, el orquestador: la carga académica de cada departamento en un campus y un periodo
 * (docs/dominio/modelo-de-datos.md, "AcademicLoad"). Depende de Academic, CatalogValidation y Demand, y de lo
 * que está a su izquierda.
 *
 * Escucha a Core, a Academic y a CatalogValidation, que no dependen de AcademicLoad: al abrir un periodo en un
 * campus crea las cargas que falten, veta borrar un departamento que tiene cargas y veta reabrir una revisión
 * de catálogo si una carga del departamento ya salió de borrador, y le dice al tablero de avance de validación
 * el estado de cada carga (LoadStatusesRequested).
 */
final class AcademicLoadServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [PublishedExport::class => PublishedExportPolicy::class];

    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateLoadsOnTermOpened::class);
        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithLoads::class);
        Event::listen(CatalogReviewReopening::class, BlockReviewReopeningAfterDraft::class);
        Event::listen(LoadStatusesRequested::class, ReportLoadStatuses::class);
        Event::listen(CareerProposalSubmitted::class, NotifyDepartmentsOnProposalSubmitted::class);
        Event::listen(CareerProposalReturned::class, NotifyCoordinatorsOnProposalReturned::class);
        Event::listen(LoadTransitioned::class, NotifyOnLoadClosingTransition::class);

        RateLimiter::for('published-schedule', fn (Request $request): Limit => Limit::perMinute(PublishedThrottle::PER_MINUTE)->by(PublishedThrottle::who($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneExports::class]);
        }
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Propuesta de carrera', 'academic-load.proposal', 'o-clipboard-document-list', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalView)),
            new NavigationItem('Bandeja del departamento', 'academic-load.inbox', 'o-inbox-arrow-down', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalReviewView)),
            new NavigationItem('Horarios publicados', 'published.index', 'o-calendar-days', NavigationArea::Academico,
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
     * asignación docente, la cierra, la publica si el campus no exige visto bueno, decide la parte de su departamento y
     * pide, ve y decide acuerdos entre departamentos (Subdirección y el jefe de proyecto docente solo los ven); el
     * coordinador arma, corrige, envía y ve la propuesta de su carrera.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadClose,
                AcademicLoadPermission::LoadReopen,
                AcademicLoadPermission::LoadApprove,
                AcademicLoadPermission::LoadPublish,
                AcademicLoadPermission::LoadPublishApproved,
                AcademicLoadPermission::ProposalView,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::AgreementView,
            ],
            Role::JefeDepartamento->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadPropose,
                AcademicLoadPermission::LoadStartStaffing,
                AcademicLoadPermission::LoadClose,
                AcademicLoadPermission::LoadPublish,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::ProposalReviewDecide,
                AcademicLoadPermission::AgreementView,
                AcademicLoadPermission::AgreementRequest,
                AcademicLoadPermission::AgreementDecide,
            ],
            Role::JefeProyectoDocente->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::AgreementView,
            ],
            Role::Coordinador->value => [
                AcademicLoadPermission::ProposalCreate,
                AcademicLoadPermission::ProposalUpdate,
                AcademicLoadPermission::ProposalSubmit,
                AcademicLoadPermission::ProposalView,
            ],
        ];
    }

    /**
     * Demo por puntos: cada carrera ofrecida en los campus del volumen propone y envía, y cada departamento acepta
     * todas sus partes (AcademicLoadDemoSeeder en modo completo); así nacen los grupos y las cargas con partes pasan a
     * propuesta, lo que lleva a «horarios». Necesita los catálogos validados del punto anterior (HC-14).
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Scheduling, 'Propuestas de carrera enviadas y aceptadas', fn (DemoContext $demo) => $demo->runSeeder(AcademicLoadDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
                'seed' => $demo->seed,
                'complete' => true,
            ])),
            // La publicación termina la etapa 8: cada carga cerrada sale con su versión 1 (tarea 4.3).
            new DemoStep(DemoCheckpoint::Reanalysis, 'Cargas cerradas publicadas con su versión 1', fn (DemoContext $demo) => $demo->runSeeder(PublicationDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
            ])),
        ];
    }

    public function demoChecks(): array
    {
        $campusIds = fn (DemoContext $demo): array => Campus::query()->whereIn('code', $demo->campuses)->pluck('id')->all();
        $proposals = fn (DemoContext $demo): Builder => CareerProposal::query()->where('term_id', $demo->term()->getKey())->whereIn('campus_id', $campusIds($demo));

        return [
            new DemoCheck([DemoCheckpoint::Reanalysis], 'Toda carga que salió de borrador está publicada con su versión 1', function (DemoContext $demo) use ($campusIds): true|string {
                $behind = AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('campus_id', $campusIds($demo))
                    ->where('status', '!=', LoadStatus::Draft)
                    ->where(fn (Builder $load) => $load->where('status', '!=', LoadStatus::Published)->orWhere('current_version', '!=', 1)
                        ->orWhereDoesntHave('snapshots', fn (Builder $snapshot) => $snapshot->where('version', 1)))
                    ->count();

                return $behind === 0 ? true : "{$behind} cargas con grupos no están publicadas en su versión 1.";
            }),
            new DemoCheck([DemoCheckpoint::Reanalysis], 'Cada foto cumple la forma del snapshot y el historial docente la sigue', function (DemoContext $demo) use ($campusIds): true|string {
                $snapshots = LoadSnapshot::query()->latestVersion()
                    ->whereIn('academic_load_id', AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('campus_id', $campusIds($demo))->select('id'))
                    ->get();
                $invalid = $snapshots->filter(fn (LoadSnapshot $snapshot): bool => SnapshotPayload::errors($snapshot->payload) !== [])->count();
                $taught = $snapshots->sum(fn (LoadSnapshot $snapshot): int => count(array_filter($snapshot->payload['groups'], fn (array $group): bool => $group['teacher'] !== null)));
                $history = TeacherSubjectHistory::query()->where('term_code', $demo->termCode)->whereIn('campus_id', $campusIds($demo))->where('source', HistorySource::Nexus)->count();

                return match (true) {
                    $snapshots->isEmpty() => 'No hay fotos publicadas.',
                    $invalid > 0 => "{$invalid} fotos no cumplen la forma v1.",
                    $history !== $taught => "El historial docente tiene {$history} filas de Nexus y las fotos dicen {$taught} grupos con docente.",
                    default => true,
                };
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Scheduling), 'Cada carrera ofrecida tiene su propuesta aceptada', function (DemoContext $demo) use ($campusIds, $proposals): true|string {
                $offered = CareerCampus::query()->whereIn('campus_id', $campusIds($demo))->where('is_active', true)->count();
                $accepted = $proposals($demo)->where('status', ProposalStatus::Accepted)->count();

                return $accepted === $offered ? true : "{$accepted} de {$offered} carreras ofrecidas tienen su propuesta aceptada.";
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Scheduling), 'Ninguna parte de propuesta pendiente ni regresada', function (DemoContext $demo) use ($proposals): true|string {
                $open = ProposalReview::query()->whereIn('career_proposal_id', $proposals($demo)->select('id'))->where('status', '!=', ProposalReviewStatus::Accepted)->count();

                return $open === 0 ? true : "{$open} partes siguen sin aceptar.";
            }),
            // Una carga se compara con las partes de su mismo campus: un departamento que imparte en Campus I y no en
            // Campus II deja su carga de Campus II en borrador.
            new DemoCheck([DemoCheckpoint::Scheduling], 'Las cargas con partes aceptadas están en propuesta', function (DemoContext $demo) use ($campusIds): true|string {
                $behind = AcademicLoad::query()->where('term_id', $demo->term()->getKey())->whereIn('campus_id', $campusIds($demo))
                    ->where('status', '!=', LoadStatus::Proposed)
                    ->whereExists(fn (QueryBuilder $parts) => $parts->selectRaw('1')->from('proposal_reviews')
                        ->join('career_proposals', 'career_proposals.id', '=', 'proposal_reviews.career_proposal_id')
                        ->whereColumn('proposal_reviews.department_id', 'academic_loads.department_id')
                        ->whereColumn('career_proposals.campus_id', 'academic_loads.campus_id')
                        ->whereColumn('career_proposals.term_id', 'academic_loads.term_id'))
                    ->count();

                return $behind === 0 ? true : "{$behind} cargas con partes aceptadas no están en propuesta.";
            }),
        ];
    }
}
