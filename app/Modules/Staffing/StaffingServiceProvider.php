<?php

namespace App\Modules\Staffing;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Console\RankTerm;
use App\Modules\Staffing\Console\RefreshTeacherLoadsCommand;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingSupplyDemoSeeder;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Listeners\QueueTeacherLoadRefresh;
use App\Modules\Staffing\Listeners\RerankOnFacultyChange;
use App\Modules\Staffing\Listeners\RerankOnGroupChange;
use App\Modules\Staffing\Listeners\RerankOnGroupsCreated;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Policies\ChangeRequestPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); puede usar Scheduling, Faculty, AcademicLoad, Academic y Core.
 *
 * Escucha a Scheduling y a Faculty, que no dependen de Staffing, para recalcular el ranking docente en la cola
 * (ComputeRankings) cuando cambia cualquiera de sus entradas.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    /**
     * Lo que recalcula `teacher_term_loads`: los grupos con docente de Scheduling y las plazas, horas externas,
     * datos y adscripciones de Faculty.
     */
    private const array REFRESH_LOADS_ON = [
        GroupTeacherChanged::class,
        GroupCancelled::class,
        GroupsCreated::class,
        PositionChanged::class,
        ExternalLoadChanged::class,
        TeacherUpdated::class,
        AdscriptionChanged::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        ChangeRequest::class => ChangeRequestPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot();

        foreach (self::REFRESH_LOADS_ON as $event) {
            Event::listen($event, QueueTeacherLoadRefresh::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RankTerm::class, RefreshTeacherLoadsCommand::class]);
        }

        Event::listen(GroupsCreated::class, RerankOnGroupsCreated::class);
        Event::listen([GroupScheduleChanged::class, GroupCancelled::class, GroupTeacherChanged::class], RerankOnGroupChange::class);
        Event::listen([
            PositionChanged::class, ExternalLoadChanged::class, AvailabilityChanged::class, QualificationChanged::class,
            AdscriptionChanged::class, TeacherUpdated::class, TeacherAccessChanged::class,
        ], RerankOnFacultyChange::class);
    }

    /**
     * «Asignación docente» para quienes ven el ranking de algún departamento (StaffingPermission::RankingView).
     *
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Asignación docente', 'staffing.assign', 'o-user-plus', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::RankingView)),
            new NavigationItem('Carga por docente', 'staffing.teacher-loads', 'o-user-group', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::TeacherLoadView)),
            new NavigationItem('Mi carga', 'staffing.my-load', 'o-calendar-days', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::MyLoadView)),
            new NavigationItem('Oferta', 'staffing.offer', 'o-squares-2x2', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::OfferView)),
            new NavigationItem('Solicitudes de cambio', 'staffing.change-requests', 'o-arrow-path-rounded-square', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(StaffingPermission::ChangeRequestView)
                    || $context->allowsAny(StaffingPermission::ChangeRequestDecide)
                    || $context->allowsAny(StaffingPermission::ChangeRequestApplySchedule)
                    || $context->allowsAny(StaffingPermission::ChangeRequestApplyRoom)),
        ];
    }

    /**
     * @return list<class-string>
     */
    public function permissions(): array
    {
        return [StaffingPermission::class];
    }

    /**
     * Ven el ranking los jefes, el jefe de proyecto docente y la Subdirección; asignan los jefes (reglas-de-negocio.md,
     * "Aprobaciones y permisos"). Solicitudes de cambio: piden el docente del grupo y el coordinador de su carrera;
     * ven los jefes, el jefe de proyecto docente y la Subdirección; decide el jefe del departamento; aplica el
     * coordinador (horario) o el jefe (salón).
     */
    public function grants(): array
    {
        return [
            Role::Docente->value => [
                StaffingPermission::OfferView,
                StaffingPermission::MyLoadView,
                StaffingPermission::ChangeRequestRequestOwn,
            ],
            Role::Subdireccion->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherLoadView,
                StaffingPermission::ChangeRequestView,
            ],
            Role::JefeDepartamento->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::TeacherLoadView,
                StaffingPermission::ChangeRequestView,
                StaffingPermission::ChangeRequestDecide,
                StaffingPermission::ChangeRequestApplyRoom,
            ],
            Role::JefeProyectoDocente->value => [
                StaffingPermission::RankingView,
                StaffingPermission::TeacherAssign,
                StaffingPermission::TeacherLoadView,
                StaffingPermission::ChangeRequestView,
            ],
            Role::Coordinador->value => [
                StaffingPermission::ChangeRequestRequest,
                StaffingPermission::ChangeRequestApplySchedule,
            ],
        ];
    }

    /**
     * «Mi espacio» es del docente sobre lo suyo: el admin no es docente, así que no tiene la oferta ni «mi carga» y
     * no los ve en el menú (y el menú no consulta la ficha del docente en cada página).
     */
    public function adminDenies(): array
    {
        return [
            StaffingPermission::OfferView,
            StaffingPermission::MyLoadView,
        ];
    }

    /**
     * Demo por puntos:
     * - a «validación», altas de docentes de asignatura donde la capacidad no llega a 1.35 veces la demanda esperada
     *   (StaffingSupplyDemoSeeder), antes de validar 20271;
     * - a «asignación», cada carga en propuesta pasa a asignación con su jefe y se calcula el ranking de todos los grupos;
     * - a «salones», docente para todo grupo activo, `teacher_term_loads` y choques al día.
     */
    public function demoSteps(): array
    {
        $staffing = fn (DemoContext $demo, string $mode) => $demo->runSeeder(StaffingDemoSeeder::class, [
            'profile' => $demo->volume === DemoVolume::Massive ? 'massive' : 'demo',
            'campuses' => $demo->campuses,
            'term' => $demo->termCode,
            'seed' => $demo->seed,
            'mode' => $mode,
        ]);

        return [
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Plantilla suficiente para la asignación', fn (DemoContext $demo) => $demo->runSeeder(StaffingSupplyDemoSeeder::class, [
                'campuses' => $demo->campuses, 'term' => $demo->termCode, 'seed' => $demo->seed,
            ])),
            new DemoStep(DemoCheckpoint::Staffing, 'Cargas a asignación y rankings', fn (DemoContext $demo) => $staffing($demo, 'start')),
            new DemoStep(DemoCheckpoint::Rooms, 'Docente para todo grupo', fn (DemoContext $demo) => $staffing($demo, 'staffed')),
        ];
    }

    public function demoChecks(): array
    {
        $groups = fn (DemoContext $demo): Builder => Group::query()->active()
            ->where('term_id', $demo->term()->getKey())
            ->whereIn('campus_id', Campus::query()->whereIn('code', $demo->campuses)->select('id'));

        return [
            new DemoCheck([DemoCheckpoint::Staffing], 'Toda carga con grupos está en asignación', function (DemoContext $demo): true|string {
                $pending = AcademicLoad::query()->where('term_id', $demo->term()->getKey())->where('status', '!=', LoadStatus::Staffing)
                    ->whereIn('campus_id', Campus::query()->whereIn('code', $demo->campuses)->select('id'))
                    ->whereExists(Group::query()->active()->selectRaw('1')
                        ->whereColumn('groups.term_id', 'academic_loads.term_id')
                        ->whereColumn('groups.campus_id', 'academic_loads.campus_id')
                        ->whereColumn('groups.department_id', 'academic_loads.department_id')
                        ->toBase())
                    ->count();

                return $pending === 0 ? true : "{$pending} cargas con grupos no están en asignación.";
            }),
            new DemoCheck([DemoCheckpoint::Staffing], 'Todo grupo activo tiene ranking', fn (DemoContext $demo): true|string => ($missing = $groups($demo)->whereNotIn('id', StaffingRanking::query()->select('group_id'))->count()) === 0
                ? true
                : "{$missing} grupos sin ranking."),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Rooms), 'Todo grupo activo tiene docente con su asignación vigente', function (DemoContext $demo) use ($groups): true|string {
                $unstaffed = $groups($demo)->whereNull('teacher_id')->count();
                $unrecorded = $groups($demo)->whereNotNull('teacher_id')
                    ->whereNotIn('id', GroupTeacherAssignment::query()->whereNull('replaced_at')->select('group_id'))->count();

                return $unstaffed === 0 && $unrecorded === 0 ? true : "{$unstaffed} grupos sin docente y {$unrecorded} con docente sin asignación vigente.";
            }),
            new DemoCheck([DemoCheckpoint::Rooms], 'La carga por docente está al día', function (DemoContext $demo) use ($groups): true|string {
                $teachers = $groups($demo)->whereNotNull('teacher_id')->distinct()->count('teacher_id');
                $loads = TeacherTermLoad::query()->where('term_id', $demo->term()->getKey())->where('groups_count', '>', 0)->count();

                return $teachers === $loads ? true : "{$teachers} docentes con grupos y {$loads} cargas por docente con grupos.";
            }),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Rooms), 'Cero choques duros de docente (HC-01, HC-08, HC-09)', fn (DemoContext $demo): true|string => ($hard = DB::table('schedule_conflicts')
                ->where('term_id', $demo->term()->getKey())->where('severity', 'hard')->whereIn('rule', ['HC-01', 'HC-08', 'HC-09'])->whereNull('resolved_at')->count()) === 0
                ? true
                : "{$hard} choques duros de docente."),
        ];
    }

    /**
     * Lo que encolaría un ranking o una carga por docente en cada escritura de la demo: los pasos de Staffing los
     * recalculan una vez.
     */
    public function demoQuietEvents(): array
    {
        return [
            GroupsCreated::class, GroupScheduleChanged::class, GroupCancelled::class, GroupTeacherChanged::class,
            PositionChanged::class, ExternalLoadChanged::class, AvailabilityChanged::class, QualificationChanged::class,
            AdscriptionChanged::class, TeacherUpdated::class, TeacherAccessChanged::class,
        ];
    }
}
