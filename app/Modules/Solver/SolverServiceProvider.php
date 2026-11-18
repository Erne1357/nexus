<?php

namespace App\Modules\Solver;

use App\Modules\AcademicLoad\Queries\LoadProgressQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Solver\Console\ExportSolverFixture;
use App\Modules\Solver\Console\PruneSolverRunFilesCommand;
use App\Modules\Solver\Console\SolverBenchmark;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Queries\CareerLoadsQuery;
use App\Modules\Solver\Rules\SolverSettings;

/**
 * Módulo Solver (fases 6 y 7, R2): el motor que sugiere horarios y salones para la carga de un departamento en una
 * modalidad, en todos sus campus (docs/arquitectura/solver.md; «Punto de partida» de la fase 6). Depende de Scheduling
 * y de lo que está a su izquierda; nadie depende de él. El motor propone y el jefe acepta: nada se escribe en
 * `group_sessions` sin aceptación.
 */
final class SolverServiceProvider extends ModuleServiceProvider
{
    /**
     * Los motores disponibles, con la etiqueta `solver.engines` (engineClasses()). Los motores no guardan estado, así
     * que el registro puede ser único por proceso; el cliente del servicio de CP-SAT se arma con `services.solver`.
     * CareerLoadsQuery recuerda sus respuestas en la petición: `scoped`, nunca único (Octane).
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(CpSatClient::class, fn (): CpSatClient => CpSatClient::fromConfig());
        $this->app->bind(CpSatEngine::class, fn ($app): CpSatEngine => new CpSatEngine($app->make(CpSatClient::class), (int) config('services.solver.poll_interval_ms', 1000)));
        $this->app->tag(self::engineClasses(), 'solver.engines');
        $this->app->singleton(EngineRegistry::class, fn ($app): EngineRegistry => new EngineRegistry($app->tagged('solver.engines')));
        $this->app->scoped(CareerLoadsQuery::class);
    }

    /**
     * Los motores del registro, en orden: el de prueba siempre; la heurística (6.6) se agrega aquí. CP-SAT (7.9) solo
     * si hay servicio configurado (SOLVER_URL y SOLVER_TOKEN, CpSatClient::configured()), así que no aparece en las
     * pruebas ni en un servidor sin el contenedor `solver`. Va primero porque, mientras no llegue
     * `solver.default_engine` (7.11), el predeterminado es el último registrado y CP-SAT no se propone por omisión
     * (supuesto por confirmar). Se decide al arrancar: cambiar la configuración pide reiniciar `app` y `queue`.
     *
     * @return list<class-string<SolverEngine>>
     */
    public static function engineClasses(): array
    {
        return [...(CpSatClient::configured() ? [CpSatEngine::class] : []), FixedEngine::class];
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ExportSolverFixture::class, PruneSolverRunFilesCommand::class, SolverBenchmark::class]);
        }
    }

    /**
     * «Corridas del motor» (tarea 6.10), al final del grupo Horarios (MN-01): para quien ve corridas de una carga o de
     * una carrera, disponible mientras haya carga en curso (MN-02, MN-04: la misma condición que las pantallas de la
     * carga).
     *
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Corridas del motor', 'solver.runs', 'o-cpu-chip', NavigationArea::Academico, group: NavigationGroup::Horarios,
                whyUnavailable: LoadProgressQuery::menuCondition(),
                visible: static fn (Actor $context): bool => $context->allowsAny(SolverPermission::DepartmentRunView) || $context->allowsAny(SolverPermission::CareerRunView)),
        ];
    }

    /**
     * Los parámetros del motor (CF-S7).
     *
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return SolverSettings::definitions();
    }

    public function permissions(): array
    {
        return [SolverPermission::class];
    }

    /**
     * El jefe de departamento lanza, ve y acepta las corridas de su carga; el coordinador lanza y ve las de su carrera;
     * Subdirección ve todas (decisión del usuario, 17 de noviembre de 2026).
     */
    public function grants(): array
    {
        return [
            Role::JefeDepartamento->value => [
                SolverPermission::DepartmentRunView,
                SolverPermission::DepartmentRunLaunch,
                SolverPermission::DepartmentRunApply,
            ],
            Role::Coordinador->value => [
                SolverPermission::CareerRunView,
                SolverPermission::CareerRunLaunch,
            ],
            Role::Subdireccion->value => [
                SolverPermission::DepartmentRunView,
                SolverPermission::CareerRunView,
                SolverPermission::SettingUpdate,
            ],
        ];
    }
}
