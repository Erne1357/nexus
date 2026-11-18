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
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
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
     * Los motores disponibles, con la etiqueta `solver.engines`: el de prueba y la heurística en PHP (6.6); CP-SAT (fase
     * 7) se agrega aquí. El orden importa: mientras no llegue `solver.default_engine` (7.11), el predeterminado es el
     * último registrado, así que la heurística va después del de prueba y es la que se propone al lanzar una corrida.
     * Los motores no guardan estado, así que el registro puede ser único por proceso. CareerLoadsQuery recuerda sus
     * respuestas en la petición: `scoped`, nunca único (Octane).
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag([FixedEngine::class, PhpHeuristicEngine::class], 'solver.engines');
        $this->app->singleton(EngineRegistry::class, fn ($app): EngineRegistry => new EngineRegistry($app->tagged('solver.engines')));
        $this->app->scoped(CareerLoadsQuery::class);
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
