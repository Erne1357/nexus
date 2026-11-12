<?php

namespace App\Modules\Scheduling;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Events\AgreementCancelled;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Events\LoadClosing;
use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\LoadProgressQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Events\RoomBlockRemoved;
use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Console\RecomputeConflicts;
use App\Modules\Scheduling\Database\Seeders\CleanScheduleDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\SchedulingAgreementsDemoSeeder;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Listeners\AssignCodeOnScheduleChange;
use App\Modules\Scheduling\Listeners\BlockClosingWithPendingGroups;
use App\Modules\Scheduling\Listeners\BlockStaffingWithHardConflicts;
use App\Modules\Scheduling\Listeners\ConfirmGroupsOnLoadClosing;
use App\Modules\Scheduling\Listeners\CreateGroupsOnPartAccepted;
use App\Modules\Scheduling\Listeners\NotifyDeciderOnAgreementRequested;
use App\Modules\Scheduling\Listeners\NotifyOwnerOnLoanCancelled;
use App\Modules\Scheduling\Listeners\NotifyRequesterOnAgreementDecided;
use App\Modules\Scheduling\Listeners\ProvideLoadSnapshot;
use App\Modules\Scheduling\Listeners\QueueConflictRecompute;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Scheduling: grupos, sesiones, cupos por carrera, validador de restricciones, editor de horarios, salones y
 * tablero de choques (docs/dominio/modelo-de-datos.md, "Scheduling"). Depende de AcademicLoad, Facilities y Faculty,
 * y de lo que está a su izquierda (ADR-0020); Staffing, a su derecha, escucha sus eventos.
 */
final class SchedulingServiceProvider extends ModuleServiceProvider
{
    /**
     * Lo que dispara el recálculo de choques: sesiones, docentes y grupos de Scheduling, salones y bloqueos de
     * Facilities, y disponibilidad, horas externas y plazas de Faculty.
     */
    private const array RECOMPUTE_ON = [
        GroupsCreated::class,
        GroupScheduleChanged::class,
        GroupTeacherChanged::class,
        GroupCancelled::class,
        RoomUpdated::class,
        RoomBlockAdded::class,
        RoomBlockRemoved::class,
        AvailabilityChanged::class,
        ExternalLoadChanged::class,
        PositionChanged::class,
    ];

    /**
     * Un validador por petición o job (Octane): varias preguntas de la misma petición (una Action y el tablero, o una
     * celda tras otra del editor) leen la foto una sola vez; quien escribe llama `forget()`. La Query del editor también
     * es `scoped`, nunca singleton: memoriza por petición lo que ya leyó.
     */
    public function register(): void
    {
        parent::register();

        $this->app->scoped(ScheduleValidator::class);
        $this->app->scoped(ScheduleEditorQuery::class);
    }

    public function boot(): void
    {
        parent::boot();

        foreach (self::RECOMPUTE_ON as $event) {
            Event::listen($event, QueueConflictRecompute::class);
        }

        Event::listen(ProposalReviewAccepted::class, CreateGroupsOnPartAccepted::class);
        Event::listen(GroupScheduleChanged::class, AssignCodeOnScheduleChange::class);

        Event::listen(LoadTransitioning::class, BlockStaffingWithHardConflicts::class);
        Event::listen(LoadTransitioning::class, BlockClosingWithPendingGroups::class);
        Event::listen(LoadClosing::class, ConfirmGroupsOnLoadClosing::class);
        Event::listen(LoadSnapshotRequested::class, ProvideLoadSnapshot::class);

        Event::listen(AgreementRequested::class, NotifyDeciderOnAgreementRequested::class);
        Event::listen(AgreementAccepted::class, NotifyRequesterOnAgreementDecided::class);
        Event::listen(AgreementRejected::class, NotifyRequesterOnAgreementDecided::class);
        Event::listen(AgreementCancelled::class, NotifyOwnerOnLoanCancelled::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RecomputeConflicts::class]);
        }
    }

    /**
     * «Horarios», el editor de horarios (tarea 3.5), y «Choques y avance», el tablero (3.4), para quien ve los horarios.
     *
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        // MN-04: sin carga en curso en el periodo activo, solo el admin las ve.
        $loadInProgress = static fn (Actor $context): ?string => app(LoadProgressQuery::class)->whyUnavailable($context->activeTerm());

        return [
            new NavigationItem('Horarios', 'scheduling.editor', 'o-calendar', NavigationArea::Academico, group: NavigationGroup::Horarios, whyUnavailable: $loadInProgress,
                visible: static fn (Actor $context): bool => $context->allowsAny(SchedulingPermission::ScheduleView)),
            new NavigationItem('Choques y avance', 'scheduling.conflicts', 'o-exclamation-triangle', NavigationArea::Academico, group: NavigationGroup::Horarios, whyUnavailable: $loadInProgress,
                visible: static fn (Actor $context): bool => $context->allowsAny(SchedulingPermission::ScheduleView)),
            new NavigationItem('Acuerdos', 'scheduling.agreements', 'o-arrows-right-left', NavigationArea::Academico, group: NavigationGroup::Horarios, whyUnavailable: $loadInProgress,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::AgreementView)),
        ];
    }

    /**
     * @return list<class-string>
     */
    public function permissions(): array
    {
        return [SchedulingPermission::class];
    }

    /**
     * Quienes ven la carga ven los horarios y el tablero, y el coordinador los de su carrera en su campus (confirmado
     * el 7 de noviembre de 2026). Crear grupos y editar sesiones es del coordinador de la carrera dueña y del jefe del
     * departamento; asignar salón y repartir cupos, del jefe del departamento que imparte.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                SchedulingPermission::ScheduleView,
            ],
            Role::JefeDepartamento->value => [
                SchedulingPermission::ScheduleView,
                SchedulingPermission::GroupManage,
                SchedulingPermission::SessionEdit,
                SchedulingPermission::RoomAssign,
                SchedulingPermission::QuotaManage,
            ],
            Role::JefeProyectoDocente->value => [
                SchedulingPermission::ScheduleView,
            ],
            Role::Coordinador->value => [
                SchedulingPermission::ScheduleView,
                SchedulingPermission::GroupManage,
                SchedulingPermission::SessionEdit,
            ],
        ];
    }

    /**
     * Demo por puntos: aceptar cada parte crea grupos y GroupsCreated recalcularía los choques del campus en cada
     * aceptación (21.9 s contra 4.1 s con el volumen demo); se descarta mientras se siembra y los choques se
     * recalculan una vez por campus al llegar a «horarios».
     *
     * @return list<class-string>
     */
    public function demoQuietEvents(): array
    {
        return [GroupsCreated::class];
    }

    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Scheduling, 'Choques recalculados una vez por campus', function (DemoContext $demo): void {
                foreach (Campus::query()->whereIn('code', $demo->campuses)->orderBy('code')->get() as $campus) {
                    app(SyncScheduleConflicts::class)->handle($demo->term(), $campus);
                }
            }),
            // Los cupos terminan la etapa 4, como el horario limpio que sigue.
            new DemoStep(DemoCheckpoint::Staffing, 'Cupos: grupos compartidos aceptados y cupos directos', fn (DemoContext $demo) => $demo->runSeeder(SchedulingAgreementsDemoSeeder::class, [
                'profile' => $demo->volume === DemoVolume::Massive ? 'massive' : 'demo',
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
                'seed' => $demo->seed,
                'mode' => 'quotas',
            ])),
            new DemoStep(DemoCheckpoint::Staffing, 'Horario limpio: horas exactas y sin choques, sin docente ni salón', fn (DemoContext $demo) => $demo->runSeeder(CleanScheduleDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
            ])),
            new DemoStep(DemoCheckpoint::Closing, 'Salón para toda sesión presencial y préstamos aceptados', fn (DemoContext $demo) => $demo->runSeeder(SchedulingAgreementsDemoSeeder::class, [
                'profile' => $demo->volume === DemoVolume::Massive ? 'massive' : 'demo',
                'campuses' => $demo->campuses,
                'term' => $demo->termCode,
                'seed' => $demo->seed,
                'mode' => 'rooms',
            ])),
        ];
    }

    public function demoChecks(): array
    {
        $groups = fn (DemoContext $demo): Builder => Group::query()->where('term_id', $demo->term()->getKey())
            ->whereIn('campus_id', Campus::query()->whereIn('code', $demo->campuses)->select('id'));

        return [
            new DemoCheck([DemoCheckpoint::Reanalysis], 'Cada foto publicada tiene los grupos no cancelados de su carga', function (DemoContext $demo) use ($groups): true|string {
                $live = $groups($demo)->where('status', '!=', GroupStatus::Cancelled->value)
                    ->get(['id', 'campus_id', 'department_id'])
                    ->groupBy(fn (Group $group): string => "{$group->campus_id}|{$group->department_id}")
                    ->map(fn ($rows) => $rows->pluck('id')->sort()->values()->all());
                $snapshots = LoadSnapshot::query()->latestVersion()->with('academicLoad')
                    ->whereHas('academicLoad', fn (Builder $load) => $load->where('term_id', $demo->term()->getKey()))
                    ->get()
                    ->filter(fn (LoadSnapshot $snapshot): bool => in_array($snapshot->payload['campus']['code'], $demo->campuses, true));
                $wrong = $snapshots->filter(fn (LoadSnapshot $snapshot): bool => collect($snapshot->payload['groups'])->pluck('id')->sort()->values()->all()
                    !== ($live["{$snapshot->academicLoad->campus_id}|{$snapshot->academicLoad->department_id}"] ?? []))->count();
                $missing = $live->keys()->diff($snapshots->map(fn (LoadSnapshot $snapshot): string => "{$snapshot->academicLoad->campus_id}|{$snapshot->academicLoad->department_id}"))->count();

                return $wrong === 0 && $missing === 0 ? true : "{$wrong} fotos no coinciden con sus grupos y {$missing} cargas con grupos no tienen foto.";
            }),
            new DemoCheck([DemoCheckpoint::Scheduling], 'Hay grupos, sin sesiones, sin cupos de otras carreras y sin acuerdos', function (DemoContext $demo) use ($groups): true|string {
                $total = $groups($demo)->count();
                $sessions = GroupSession::query()->whereIn('group_id', $groups($demo)->select('id'))->count();
                $foreignQuotas = GroupQuota::query()->join('groups', 'groups.id', '=', 'group_quotas.group_id')
                    ->whereIn('groups.id', $groups($demo)->select('id'))->whereColumn('group_quotas.career_id', '!=', 'groups.owner_career_id')->count();
                $agreements = InterdepartmentalAgreement::query()->where('term_id', $demo->term()->getKey())->count();

                return match (true) {
                    $total === 0 => 'No hay grupos.',
                    $sessions > 0 => "{$sessions} sesiones ya colocadas.",
                    $foreignQuotas > 0 => "{$foreignQuotas} cupos de otras carreras.",
                    $agreements > 0 => "{$agreements} acuerdos entre departamentos.",
                    default => true,
                };
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Staffing), 'Cupos decididos: hay grupos compartidos aceptados y ningún acuerdo pendiente', function (DemoContext $demo): true|string {
                $agreements = InterdepartmentalAgreement::query()->where('term_id', $demo->term()->getKey())
                    ->whereIn('campus_id', Campus::query()->whereIn('code', $demo->campuses)->select('id'));
                $pending = (clone $agreements)->where('status', AgreementStatus::Proposed->value)->count();
                $shared = (clone $agreements)->where('kind', AgreementKind::SharedGroup->value)->where('status', AgreementStatus::Accepted->value)->count();

                return match (true) {
                    $pending > 0 => "{$pending} acuerdos siguen pendientes.",
                    $shared === 0 => 'No hay grupos compartidos aceptados.',
                    default => true,
                };
            }),
            new DemoCheck([DemoCheckpoint::Staffing], 'La oferta de salones alcanza para el horario', function (DemoContext $demo): true|string {
                $seeder = app(SchedulingAgreementsDemoSeeder::class);
                $missing = Campus::query()->whereIn('code', $demo->campuses)->orderBy('code')->get()
                    ->flatMap(fn (Campus $campus): array => $campus->profile->usesRooms ? $seeder->missingRooms($demo->term(), $campus) : [])->all();

                return $missing === [] ? true : count($missing).' sesiones no tendrían salón sin choque, por ejemplo: '.implode('; ', array_slice($missing, 0, 3)).'.';
            }),
            new DemoCheck([DemoCheckpoint::Closing], 'Toda sesión presencial tiene salón, sin choques de salón (HC-02, HC-05, HC-06, HC-10, HC-11)', function (DemoContext $demo) use ($groups): true|string {
                $roomless = GroupSession::query()->whereIn('group_id', $groups($demo)->where('status', '!=', GroupStatus::Cancelled->value)->select('id'))
                    ->whereNull('room_id')->where('kind', '!=', SessionKind::Virtual->value)->count();
                $conflicts = ScheduleConflict::query()->whereIn('group_id', $groups($demo)->select('id'))
                    ->whereIn('rule', ['HC-02', 'HC-05', 'HC-06', 'HC-10', 'HC-11'])->whereNull('resolved_at')->count();

                return match (true) {
                    $roomless > 0 => "{$roomless} sesiones presenciales sin salón.",
                    $conflicts > 0 => "{$conflicts} choques de salón.",
                    default => true,
                };
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Scheduling), 'Ningún grupo de materia o plan inactivo ni de carrera no ofrecida (HC-13)', function (DemoContext $demo) use ($groups): true|string {
                $conflicts = ScheduleConflict::query()->whereIn('group_id', $groups($demo)->select('id'))->where('rule', 'HC-13')->whereNull('resolved_at')->count();

                return $conflicts === 0 ? true : "{$conflicts} choques HC-13.";
            }),
            // Desde «asignacion», lo que deja el horario limpio (#338): todo grupo activo con horario y cero choques duros
            // vigentes (los pasos que cambian horarios, docentes o salones recalculan al terminar); en «asignacion»,
            // todavía sin docentes.
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Staffing), 'Todo grupo activo tiene horario', fn (DemoContext $demo): true|string => ($missing = $groups($demo)->where('status', '!=', GroupStatus::Cancelled)->whereDoesntHave('sessions')->count()) === 0
                ? true
                : "{$missing} grupos activos sin horario."),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Staffing), 'Cero choques duros', function (DemoContext $demo) use ($groups): true|string {
                $hard = ScheduleConflict::query()->whereIn('group_id', $groups($demo)->select('id'))
                    ->where('severity', ConflictSeverity::Hard)->whereNull('resolved_at')
                    ->get(['rule'])->countBy('rule');

                return $hard->isEmpty()
                    ? true
                    : 'Choques duros: '.$hard->map(fn (int $count, string $rule): string => "{$rule} ({$count})")->implode(', ').'.';
            }),
            new DemoCheck([DemoCheckpoint::Staffing], 'Ningún grupo tiene docente todavía', fn (DemoContext $demo): true|string => ($assigned = $groups($demo)->where('status', '!=', GroupStatus::Cancelled)->whereNotNull('teacher_id')->count()) === 0
                ? true
                : "{$assigned} grupos ya tienen docente."),
        ];
    }
}
