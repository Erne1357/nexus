<?php

namespace App\Modules\Solver;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Solver\Enums\SolverPermission;

/**
 * Módulo Solver (fases 6 y 7, R2): el motor que sugiere horarios y salones para la carga de un departamento en una
 * modalidad, en todos sus campus (docs/arquitectura/solver.md; «Punto de partida» de la fase 6). Depende de Scheduling
 * y de lo que está a su izquierda; nadie depende de él. El motor propone y el jefe acepta: nada se escribe en
 * `group_sessions` sin aceptación.
 */
final class SolverServiceProvider extends ModuleServiceProvider
{
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
            ],
        ];
    }
}
