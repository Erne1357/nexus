<?php

namespace App\Modules\Core;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\DefaultScopeLabels;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\RoleAccess;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Console\MigrationPlanCommand;
use App\Modules\Core\Console\ProvisionStaffCommand;
use App\Modules\Core\Console\SeedDemo;
use App\Modules\Core\Console\SyncTermsFromData;
use App\Modules\Core\Credentials\CredentialPhotos;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoAdminPasswordSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Database\Seeders\TermHistorySeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Health\Checks\HeartbeatCheck;
use App\Modules\Core\Http\Controllers\HealthEndpoint;
use App\Modules\Core\Http\Middleware\EnsureAcademicLoadIsVisible;
use App\Modules\Core\Jobs\RecordQueueHeartbeat;
use App\Modules\Core\Listeners\ForgetAcademicLoadVisibility;
use App\Modules\Core\Listeners\ForgetActiveRole;
use App\Modules\Core\Listeners\ForgetRoleAccess;
use App\Modules\Core\Listeners\ForgetTermCalendar;
use App\Modules\Core\Listeners\HoldAcademicLoadMail;
use App\Modules\Core\Listeners\LogFailedQueuedMail;
use App\Modules\Core\Listeners\LogSentMail;
use App\Modules\Core\Listeners\StampActingAs;
use App\Modules\Core\Livewire\ActiveRoleHook;
use App\Modules\Core\Livewire\BlockViewDataCalls;
use App\Modules\Core\Mail\MicrosoftGraphTransport;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Policies\ActivityPolicy;
use App\Modules\Core\Queries\NoUnitAdscriptions;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Core\Support\ActivityAttributes;
use App\Modules\Core\Support\ActivityProtectedProperties;
use App\Modules\Core\Support\QueueJobScope;
use App\Modules\Core\View\Components\Drawer;
use App\Modules\Core\View\DrawerIds;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\ComponentHookRegistry;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

final class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * La bitácora es de un paquete (spatie/activitylog), así que su policy no sale de la convención.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [Activity::class => ActivityPolicy::class];

    public function register(): void
    {
        // Estático y en register(): Livewire cablea sus hooks al arrancar (ComponentHookRegistry::boot()), y un hook
        // registrado después, con Livewire::componentHook() desde boot(), ya no se engancha.
        ComponentHookRegistry::register(ActiveRoleHook::class);
        ComponentHookRegistry::register(BlockViewDataCalls::class);

        // `scoped`, nunca singleton: con Octane un singleton guardaría el alcance de un usuario
        // para las peticiones siguientes.
        $this->app->scoped(Actor::class, fn (Application $app): Actor => new Actor(
            fn (): mixed => $app['auth']->user(),
            $app->make(OrgTree::class),
            $app->make(AuthorizationSnapshots::class),
            $app->make(GrantTable::class),
            $app->make(RoleAccess::class),
            $app['session.store'],
        ));

        // `scoped` por lo mismo: memoriza la versión de las concesiones y su mapa en la petición en curso (CF-01).
        $this->app->scoped(GrantTable::class, fn (Application $app): GrantTable => new GrantTable(
            $app->make(PermissionRegistry::class),
            new PermissionGrantRules($app->make(PermissionRegistry::class)),
            $app['cache.store'],
        ));

        // `scoped` por lo mismo: guarda en la instancia la huella y el mapa de la petición en curso.
        $this->app->scoped(OrgTree::class, fn (Application $app): OrgTree => new OrgTree($app['cache.store']));
        $this->app->scoped(DrawerIds::class);

        // Singleton a propósito: las fuentes de fotos se registran una vez al arrancar (boot) y son closures sin estado;
        // nada de lo que guarda cambia por petición, así que es seguro con Octane.
        $this->app->singleton(CredentialPhotos::class);

        // `scoped` por lo mismo: memoriza los snapshots de nombramientos de la petición en curso.
        $this->app->scoped(AuthorizationSnapshots::class, fn (Application $app): AuthorizationSnapshots => new AuthorizationSnapshots($app['cache.store']));

        // `scoped` por lo mismo: lee una vez por petición si el admin ocultó la carga académica (MN-05).
        $this->app->scoped(AcademicLoadVisibility::class);

        // `scoped`: si lo que corre es un trabajo del worker de la cola (tarea 1.29); el worker la olvida entre trabajos.
        $this->app->scoped(QueueJobScope::class);

        // `scoped` por lo mismo: lee una vez por petición qué perfiles inician sesión (AC-21).
        $this->app->scoped(RoleAccess::class);

        // Singleton porque es inmutable: las entradas de todos los módulos, sin nada del usuario.
        $this->app->singleton(Navigation::class, fn (Application $app): Navigation => Navigation::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Solo lo usa la consola (`demo:seed`): se arma cuando se pide, con los pasos de todos los módulos.
        $this->app->bind(DemoPlan::class, fn (Application $app): DemoPlan => DemoPlan::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Sin estado: nombra lo que Core no conoce. Un módulo con esos datos lo reemplaza en su register().
        $this->app->bindIf(ScopeLabels::class, DefaultScopeLabels::class);

        // Sin estado: quién está adscrito a cada unidad del Organigrama. Faculty lo reemplaza con sus adscripciones.
        $this->app->bindIf(UnitAdscriptions::class, NoUnitAdscriptions::class);

        // Singleton porque es inmutable: los parámetros que declaran los módulos, sin valores ni nada de la petición.
        $this->app->singleton(SettingRegistry::class, fn (Application $app): SettingRegistry => SettingRegistry::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Singleton porque es inmutable: las etiquetas de columnas y enums que declaran los módulos para la bitácora.
        $this->app->singleton(ActivityAttributes::class, fn (Application $app): ActivityAttributes => ActivityAttributes::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Singleton porque es inmutable: las propiedades reservadas de la bitácora que declaran los módulos, sin nada de
        // quien mira (lo dice el Actor de cada llamada).
        $this->app->singleton(ActivityProtectedProperties::class, fn (Application $app): ActivityProtectedProperties => ActivityProtectedProperties::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));

        // Singleton porque es inmutable: el catálogo de permisos, lo de fábrica por puesto y los candados de todos
        // los módulos, sin nada del usuario ni de la petición. Lo que vale hoy lo dice GrantTable.
        $this->app->singleton(PermissionRegistry::class, fn (Application $app): PermissionRegistry => PermissionRegistry::fromModules(
            $app->getProviders(ModuleServiceProvider::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        // El x-drawer de MaryUI con id de casilla único en la página y estable entre renders (issue #754); registrado después del paquete, gana el alias.
        Blade::component('mary-drawer', Drawer::class);
        Blade::component(config('mary.prefix').'drawer', Drawer::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SeedDemo::class, SyncTermsFromData::class, MigrationPlanCommand::class, ProvisionStaffCommand::class]);
        }

        // `/health` (tarea 1.31) fuera del grupo `web`: sin sesión, cookies ni throttle, para que un Valkey caído dé 503 y no 500.
        Route::get('/health', HealthEndpoint::class)->name('health');

        // Los latidos de `/health`: el scheduler escribe el suyo y manda un job que escribe el de la cola.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                Cache::store(config('health.cache_store'))->put(HeartbeatCheck::SCHEDULER_KEY, now()->timestamp, 600);
                dispatch(new RecordQueueHeartbeat);
            })->everyMinute()->name('health-heartbeat');
        });

        Event::listen(TermCalendarChanged::class, ForgetTermCalendar::class);
        Event::listen([SettingsUpdated::class, RequestHandled::class], ForgetAcademicLoadVisibility::class);
        Event::listen([SettingsUpdated::class, RequestHandled::class], ForgetRoleAccess::class);
        Event::listen(NotificationSending::class, HoldAcademicLoadMail::class);
        Event::listen(Login::class, ForgetActiveRole::class);
        Activity::creating(StampActingAs::class);

        // MN-05: el bloqueo de la carga oculta (va en el grupo `web`, bootstrap/app.php) también se revisa en cada acción de
        // Livewire, con la ruta de la página que pintó el componente.
        Livewire::addPersistentMiddleware([EnsureAcademicLoadIsVisible::class]);
        Event::listen(MessageSent::class, LogSentMail::class);
        Event::listen(JobFailed::class, LogFailedQueuedMail::class);

        // El correo por Microsoft Graph con permisos delegados (tarea 1.29, ADR-0032): `MAIL_MAILER=microsoft-graph`. El
        // transporte no guarda nada entre correos (lee la cuenta de la base en cada uno), así que MailManager lo puede
        // conservar en el worker.
        Mail::extend('microsoft-graph', fn (): MicrosoftGraphTransport => $this->app->make(MicrosoftGraphTransport::class));

        // Solo un trabajo del worker de la cola puede esperar a Microsoft (429); la cola `sync` corre dentro de la petición.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(QueueJobScope::class)->begin();
            }
        });
        Event::listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], fn (): mixed => $this->app->make(QueueJobScope::class)->end());

        // Se arma cuando ya arrancaron todos los módulos y antes de la primera petición, para que
        // Octane lo cree una vez por worker y no en cada petición.
        $this->app->booted(function (Application $app): void {
            $app->make(Navigation::class);
            $app->make(PermissionRegistry::class);
            $app->make(SettingRegistry::class);
        });
    }

    /**
     * Las columnas que se repiten en toda la bitácora (`*`) y las de los modelos de Core cuyo nombre cambia de sentido.
     */
    public function activityAttributes(): array
    {
        return [
            '*' => [
                'code' => 'Código', 'name' => 'Nombre', 'username' => 'Usuario', 'email' => 'Correo',
                'institutional_email' => 'Correo institucional', 'email_verified_at' => 'Correo confirmado',
                'is_active' => 'Activo', 'is_primary' => 'Principal', 'is_current' => 'Vigente', 'deleted' => 'Borrado',
                'must_change_password' => 'Debe cambiar su contraseña', 'access' => ['Acceso', UserAccess::class],
                'password_origin' => ['Origen de la contraseña', PasswordOrigin::class],
                'access_invited_at' => 'Invitación enviada', 'access_invited_by' => 'Invitación enviada por',
                'deactivated_at' => 'Dada de baja', 'deactivated_by' => 'Dada de baja por', 'last_login_at' => 'Último acceso',
                'role' => ['Puesto', Role::class], 'stage' => ['Etapa', Stage::class],
                'title' => 'Título', 'description' => 'Descripción', 'note' => 'Nota', 'notes' => 'Notas', 'comment' => 'Comentario',
                'reason' => 'Motivo', 'url' => 'Enlace', 'link_url' => 'Enlace', 'image_path' => 'Imagen', 'file_path' => 'Archivo',
                'starts_at' => 'Inicio', 'ends_at' => 'Fin', 'starts_on' => 'Empieza', 'ends_on' => 'Termina',
                'valid_from' => 'Vigente desde', 'valid_to' => 'Vigente hasta', 'day' => 'Día', 'sort_order' => 'Orden', 'sort' => 'Orden',
                'order' => 'Orden', 'semester' => 'Semestre', 'level' => 'Nivel', 'capacity' => 'Capacidad', 'features' => 'Características',
                'settings' => 'Configuración', 'permissions' => 'Permisos', 'columns' => 'Columnas', 'quotas' => 'Cupos',
                'availability' => 'Disponibilidad', 'status' => 'Estado', 'kind' => 'Tipo', 'type' => 'Tipo', 'key' => 'Parámetro', 'value' => 'Valor',
                'campus_id' => 'Campus', 'department_id' => 'Departamento', 'career_id' => 'Carrera', 'building_id' => 'Edificio',
                'room_id' => 'Salón', 'term_id' => 'Periodo', 'modality_id' => 'Modalidad', 'user_id' => 'Usuario',
                'teacher_id' => 'Docente', 'subject_id' => 'Materia', 'group_id' => 'Grupo', 'parent_id' => 'Unidad superior',
                'study_plan_id' => 'Plan de estudios', 'org_unit_id' => 'Unidad del organigrama', 'specialty_id' => 'Especialidad',
                'prerequisite_subject_id' => 'Materia requisito', 'review_id' => 'Validación',
                'applied_at' => 'Aplicado', 'applied_by' => 'Aplicado por', 'decided_at' => 'Decidido', 'decided_by' => 'Decidido por',
                'validated_at' => 'Validado', 'validated_by' => 'Validado por', 'resolved_at' => 'Resuelto', 'resolved_by' => 'Resuelto por',
                'resolution_note' => 'Nota de la resolución', 'follows_factory' => 'Sigue lo de fábrica',
            ],
            Setting::class => ['key' => 'Parámetro', 'value' => 'Valor', 'scope' => 'Alcance', 'scope_id' => 'Alcance (registro)'],
            Term::class => ['code' => 'Clave', 'name' => 'Nombre', 'starts_on' => 'Inicio del periodo', 'ends_on' => 'Fin del periodo', 'is_current' => 'Periodo vigente'],
            TermStage::class => ['status' => ['Estado', StageStatus::class]],
            OrgUnit::class => ['kind' => 'Tipo de unidad', 'sort' => 'Orden', 'name' => 'Nombre de la unidad'],
            RoleAssignment::class => ['valid_from' => 'Desde', 'valid_to' => 'Hasta'],
            LoginNotice::class => ['title' => 'Título', 'link_url' => 'Enlace', 'image_path' => 'Imagen'],
            LoginShortcut::class => ['kind' => ['Tipo', LoginShortcutKind::class]],
            PermissionSet::class => ['name' => 'Nombre del puesto', 'description' => 'Descripción'],
            User::class => ['name' => 'Nombre', 'is_active' => 'Activo'],
        ];
    }

    /**
     * CF-05 (confirmado el 20 de noviembre de 2026): la nota libre de la baja de una cuenta solo la ve quien da de baja
     * cuentas, el admin (`core.user.deactivate`, candado); su motivo sale a quien ve la bitácora.
     */
    public function activityProtectedProperties(): array
    {
        return ['deactivated' => ['note' => CorePermission::UserDeactivate]];
    }

    public function navigation(): array
    {
        return [
            // El Inicio de antes (tarea 1.24.1): para todo el que tiene puesto, primero en Mi espacio.
            new NavigationItem('Resumen', 'core.summary', 'o-home', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->activeRole() !== null),
            new NavigationItem('Campus', 'core.campuses', 'o-building-office-2', NavigationArea::Administracion, group: NavigationGroup::Institucion,
                ability: 'viewAny', arguments: [Campus::class]),
            new NavigationItem('Modalidades', 'core.modalities', 'o-squares-2x2', NavigationArea::Administracion, group: NavigationGroup::Institucion,
                ability: 'viewAny', arguments: [Modality::class]),
            new NavigationItem('Periodos', 'core.terms', 'o-calendar-days', NavigationArea::Administracion, group: NavigationGroup::Institucion,
                ability: 'viewAny', arguments: [Term::class], childRoutes: ['core.terms.show']),
            new NavigationItem('Organigrama', 'core.org-chart', 'o-rectangle-group', NavigationArea::Administracion, group: NavigationGroup::Institucion,
                ability: 'viewAny', arguments: [OrgUnit::class]),
            new NavigationItem('Personas y puestos', 'core.people', 'o-users', NavigationArea::Administracion, group: NavigationGroup::Institucion,
                ability: 'viewAny', arguments: [RoleAssignment::class], childRoutes: ['core.people.show']),
            // Panel de configuración (1.22): cada paquete agrega su entrada debajo de su marca, en el grupo Configuración.
            // 1.22-B Puestos y permisos
            new NavigationItem('Puestos y permisos', 'core.permissions', 'o-key', NavigationArea::Administracion, group: NavigationGroup::Configuracion,
                ability: 'viewAny', arguments: [PermissionSet::class]),
            // 1.22-D Bitácora
            new NavigationItem('Bitácora', 'core.activity', 'o-clipboard-document-list', NavigationArea::Administracion, group: NavigationGroup::Configuracion,
                ability: 'viewAny', arguments: [Activity::class]),
            // 1.22-E Avisos y atajos del login: en Configuración para quien administra los puestos y permisos (el admin,
            // candado CF-03); los demás que los manejan (Comunicación y Difusión, CO-01) la ven en el área de Comunicación,
            // que declara el módulo Communication con la condición contraria.
            new NavigationItem('Avisos y atajos', 'core.login-content', 'o-megaphone', NavigationArea::Administracion, group: NavigationGroup::Configuracion,
                visible: static fn (Actor $context): bool => $context->allowsAny(CorePermission::AuthzView)
                    && ($context->allowsAny(CorePermission::LoginNoticeView) || $context->allowsAny(CorePermission::LoginShortcutView))),
            // 1.22-F Parámetros
            new NavigationItem('Parámetros', 'core.settings', 'o-adjustments-horizontal', NavigationArea::Administracion, group: NavigationGroup::Configuracion,
                ability: 'viewAny', arguments: [Setting::class]),
            // 1.22-G Correos y colas
            new NavigationItem('Correos y colas', 'core.mail-and-queues', 'o-envelope', NavigationArea::Administracion, group: NavigationGroup::Configuracion,
                ability: 'viewAny', arguments: [MailLog::class]),
        ];
    }

    public function permissions(): array
    {
        return [CorePermission::class];
    }

    /**
     * El interruptor de la carga académica (MN-05) y qué perfiles inician sesión (AC-21), que solo cambia el admin.
     */
    public function settings(): array
    {
        return [AcademicLoadVisibility::definition(), RoleAccess::definition()];
    }

    /**
     * CO-01 (C10 a C12, confirmados el 24 de noviembre de 2026): el jefe de departamento y el personal de área tienen los
     * avisos, los atajos y las fotos del personal, y solo el jefe nombra al personal de su unidad (CO-02). Todos aplican
     * solo a Comunicación y Difusión (CorePermission::appliesTo()), así que el jefe o el personal de otra unidad no
     * reciben nada.
     */
    public function grants(): array
    {
        $communication = [
            CorePermission::LoginNoticeView,
            CorePermission::LoginNoticeManage,
            CorePermission::LoginShortcutView,
            CorePermission::LoginShortcutManage,
            CorePermission::StaffPhotoManage,
        ];

        return [
            Role::JefeDepartamento->value => [...$communication, CorePermission::UnitStaffAppoint],
            Role::Personal->value => $communication,
            Role::Directivo->value => [
                CorePermission::CampusView,
                CorePermission::ModalityView,
                CorePermission::TermView,
                ...$this->factoryLockExceptions()[Role::Directivo->value],
            ],
            Role::Subdireccion->value => [
                CorePermission::CampusView,
                CorePermission::ModalityView,
                CorePermission::ModalityUpdateProfile,
                CorePermission::TermView,
                CorePermission::TermCreate,
                CorePermission::TermUpdate,
                CorePermission::TermMakeCurrent,
                CorePermission::TermManageCalendar,
                CorePermission::TermOpen,
                CorePermission::SettingView,
            ],
        ];
    }

    /**
     * CF-03 (confirmado el 14 de noviembre de 2026): usuarios, roles y permisos, nombramientos, bitácora, correos y
     * colas son solo del admin. El organigrama también, porque decide el alcance de todos (supuesto por confirmar). Y,
     * desde el 15 de noviembre de 2026, editar un campus o una modalidad, porque desactivarlos les quita el alcance a
     * todos (confirmado). El perfil de flujo de una modalidad no es candado: la Subdirección lo tiene de fábrica. Los
     * avisos y atajos del login y los parámetros sí se pueden dar. Desde la tarea 1.24.6, también el interruptor de la carga
     * académica y verla oculta (`core.academic_load.*`, MN-05): la oculta a todos menos al admin (supuesto por confirmar
     * que no se puedan dar). Siguen siendo candados aunque el directivo tenga de fábrica tres lecturas
     * (factoryLockExceptions()).
     */
    public function adminOnly(): array
    {
        $locked = ['user', 'authz', 'assignment', 'activity', 'mail', 'queue', 'org_unit', 'academic_load', 'access'];

        return [
            ...array_values(array_filter(
                CorePermission::cases(),
                fn (CorePermission $permission): bool => in_array(explode('.', $permission->value)[1], $locked, true),
            )),
            CorePermission::CampusUpdate,
            CorePermission::ModalityUpdate,
        ];
    }

    /**
     * AZ-03 (confirmado el 20 de noviembre de 2026): el directivo consulta, de fábrica y de solo lectura, «Personas y
     * puestos» (la lista y la ficha), el Organigrama y la Bitácora, de todo el Tec. Siguen siendo candados (CF-03): el
     * panel no los da a otro puesto. Lo demás del admin (roles y permisos, usuarios, correos y colas, nombrar, terminar,
     * editar el organigrama, la carga oculta) no lo tiene (supuesto por confirmar).
     */
    public function factoryLockExceptions(): array
    {
        return [
            Role::Directivo->value => [
                CorePermission::AssignmentView,
                CorePermission::OrgUnitView,
                CorePermission::ActivityView,
            ],
        ];
    }

    /**
     * Demo por puntos: lo base, el admin con la contraseña demo y los periodos 20261, 20263 y 20271 llevan a
     * «catálogos»; abrir 20271 en las modalidades del volumen (M4; DemoVolume::modalities(): Presencial; en el masivo
     * también 20263, la foto anterior de la validación) lleva a «validación». En el masivo, el historial de periodos desde
     * 2014 va justo antes de abrir,
     * después de los alumnos, como en MassiveSeeder (StudentsDemoSeeder liga el periodo de ingreso solo a los que ya
     * existen). Sin TermCalendarDemoSeeder: el calendario de 20271 lo escribe DemoRunner, sin ventanas propias de
     * Presencial, y 20263 queda sin calendario.
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Campus, organigrama, admin y periodos', function (DemoContext $demo): void {
                foreach ([CampusSeeder::class, OrgChartSeeder::class, AdminUserSeeder::class, DemoAdminPasswordSeeder::class, DemoTermSeeder::class] as $seeder) {
                    $demo->runSeeder($seeder);
                }
            }),
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Abrir Ene-Jun 2027', function (DemoContext $demo): void {
                if ($demo->volume === DemoVolume::Massive) {
                    $demo->runSeeder(TermHistorySeeder::class);
                }

                $modalities = $this->demoModalityCodes($demo);

                $demo->runSeeder(TermOpeningDemoSeeder::class, [
                    'openings' => $demo->volume === DemoVolume::Massive
                        ? [$demo->termCode => $modalities, '20263' => $modalities]
                        : [$demo->termCode => $modalities],
                ]);
            }),
        ];
    }

    public function demoChecks(): array
    {
        $openedIn = fn (DemoContext $demo): int => TermOpening::query()
            ->where('term_id', $demo->term()->getKey())
            ->whereIn('modality_id', Modality::query()->whereIn('code', $this->demoModalityCodes($demo))->select('id'))
            ->count();

        return [
            new DemoCheck([DemoCheckpoint::Catalogs], 'Ene-Jun 2027 todavía sin abrir', fn (DemoContext $demo): true|string => ($opened = $openedIn($demo)) === 0
                ? true
                : "Ya está abierto en {$opened} modalidades."),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::CatalogValidation), 'Ene-Jun 2027 abierto en las modalidades del volumen', fn (DemoContext $demo): true|string => ($opened = $openedIn($demo)) === count($this->demoModalityCodes($demo))
                ? true
                : sprintf('Abierto en %d de %d modalidades.', $opened, count($this->demoModalityCodes($demo)))),
            new DemoCheck(DemoCheckpoint::staged(), 'El admin entra con la contraseña demo', function (DemoContext $demo): true|string {
                $admin = $demo->admin();

                return Hash::check(DemoAdminPasswordSeeder::PASSWORD, $admin->password) && ! $admin->must_change_password
                    ? true
                    : 'El admin no tiene la contraseña demo o debe cambiarla.';
            }),
        ];
    }

    /**
     * Las claves de las modalidades del volumen que existen (M4; DemoVolume::modalities()): Presencial.
     *
     * @return list<string>
     */
    private function demoModalityCodes(DemoContext $demo): array
    {
        return Modality::query()->whereIn('code', $demo->volume->modalities())->orderBy('code')->pluck('code')->all();
    }
}
