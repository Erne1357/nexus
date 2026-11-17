<?php

namespace App\Modules\Solver;

use App\Modules\Core\ModuleServiceProvider;

/**
 * Módulo Solver (fases 6 y 7, R2): el motor que sugiere horarios y salones para la carga de un departamento en una
 * modalidad, en todos sus campus (docs/arquitectura/solver.md; «Punto de partida» de la fase 6). Depende de Scheduling
 * y de lo que está a su izquierda; nadie depende de él. El motor propone y el jefe acepta: nada se escribe en
 * `group_sessions` sin aceptación.
 */
final class SolverServiceProvider extends ModuleServiceProvider {}
