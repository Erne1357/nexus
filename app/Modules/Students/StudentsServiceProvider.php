<?php

namespace App\Modules\Students;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Students\Models\Student;

/**
 * Módulo Students: alumnos, su kardex y su avance reticular (docs/dominio/modelo-de-datos.md, "Students").
 * Depende de Academic y Core. Se llena desde Imports: en R1 y R2 es de solo lectura.
 */
final class StudentsServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [
            new NavigationItem('Alumnos', 'students.index', 'o-academic-cap', NavigationArea::ServiciosEscolares,
                ability: 'viewAny', arguments: [Student::class], childRoutes: ['students.show']),
        ];
    }
}
