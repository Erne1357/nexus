<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\StartReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;

/**
 * La primera captura del jefe de departamento sobre un docente de su departamento (perfil, disponibilidad, horas
 * fuera del ITCJ, ficha, adscripción o plaza) pasa la revisión de docentes del periodo actual de pendiente a en
 * revisión (StartReview), como su primera corrección (CV-S4, extensión por confirmar). Quien actúa es el usuario de
 * la sesión: los eventos de Faculty no lo traen. El admin, Subdirección y el jefe de proyecto docente no la mueven,
 * ni una revisión ya en revisión o validada. Sale después del commit de la Action de Faculty.
 */
final class StartTeachersReviewOnFacultyCapture
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly StartReview $startReview,
    ) {}

    public function handle(AvailabilityChanged|QualificationChanged|ExternalLoadChanged|TeacherUpdated|AdscriptionChanged|PositionChanged $event): void
    {
        $actor = $this->context->user();

        if ($actor === null || $this->context->isAdmin() || ! $this->context->has(Role::JefeDepartamento)) {
            return;
        }

        $teacher = $this->teacherOf($event);
        $departments = $teacher->adscriptions()->pluck('department_id')
            ->filter(fn (string $department): bool => $this->context->coversDepartment(Role::JefeDepartamento, $department));

        if ($departments->isEmpty()) {
            return;
        }

        CatalogReview::query()
            ->whereIn('term_id', Term::query()->current()->select('id'))
            ->whereIn('department_id', $departments)
            ->where('catalog', Catalog::Teachers)
            ->where('status', ReviewStatus::Pending)
            ->get()
            ->each(fn (CatalogReview $review) => $this->startReview->handle($review, $actor));
    }

    private function teacherOf(object $event): Teacher
    {
        return match (true) {
            $event instanceof AvailabilityChanged, $event instanceof TeacherUpdated => $event->teacher,
            $event instanceof QualificationChanged => $event->qualification->teacher,
            $event instanceof ExternalLoadChanged => $event->load->teacher,
            $event instanceof AdscriptionChanged => $event->adscription->teacher,
            $event instanceof PositionChanged => $event->position->teacher,
        };
    }
}
