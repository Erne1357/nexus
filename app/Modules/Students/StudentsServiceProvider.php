<?php

namespace App\Modules\Students;

use App\Modules\Core\ModuleServiceProvider;

/**
 * Módulo Students: alumnos, su kardex y su avance reticular (docs/dominio/modelo-de-datos.md, "Students").
 * Depende de Academic y Core. Se llena desde Imports: en R1 y R2 es de solo lectura.
 */
final class StudentsServiceProvider extends ModuleServiceProvider {}
