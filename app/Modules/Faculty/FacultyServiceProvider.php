<?php

namespace App\Modules\Faculty;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Listeners\BlockDepartmentDeletionWithTeachers;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Faculty: docentes, sus adscripciones a departamentos y sus plazas (docs/dominio/modelo-de-datos.md,
 * "Faculty"). Depende de Core y Academic.
 */
final class FacultyServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithTeachers::class);
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Docentes', 'faculty.teachers', 'o-users', NavigationArea::RecursosHumanos,
                ability: 'viewAny', arguments: [Teacher::class]),
        ];
    }
}
