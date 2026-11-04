<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\StartReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use Illuminate\Support\Collection;

/**
 * La primera captura del jefe de departamento sobre un docente de su departamento (perfil, disponibilidad, horas
 * fuera del ITCJ, ficha, adscripción o plaza) pasa la revisión de docentes de pendiente a en revisión
 * (StartReview), como su primera corrección (CV-S4, extensión por confirmar).
 *
 * Quien actúa es el `actor` del evento (null si no hubo sesión: no hace nada), así que sirve también desde un job.
 * El periodo es el del evento si lo trae (disponibilidad, horas fuera); si no, el activo del actor, el mismo que
 * usan la validación y el editor de disponibilidad, que sin elección es el actual. El departamento es cualquiera
 * de los del docente que el actor cubre como jefe, incluido el de la adscripción que se acaba de quitar. El admin,
 * Subdirección y el jefe de proyecto docente no la mueven, ni una revisión ya en revisión o validada.
 *
 * Si el actor es quien está en sesión, cuenta su perfil activo (Actor): la mueve si actúa como jefe del
 * departamento y su perfil concede corregir esa revisión (`catalog_validation.review.correct`, la regla de
 * CatalogReviewPolicy::correct), así que el jefe que entró como coordinador no la mueve. Si el actor es otro usuario (forUser()) o no hay usuario autenticado (un job), basta con que sea jefe aunque tenga otros
 * puestos; el código de consola que fija el usuario del guard recibe el perfil predeterminado. Sale después del
 * commit de la Action de Faculty.
 */
final class StartTeachersReviewOnFacultyCapture
{
    public function __construct(
        private readonly Actor $context,
        private readonly StartReview $startReview,
    ) {}

    public function handle(AvailabilityChanged|QualificationChanged|ExternalLoadChanged|TeacherUpdated|AdscriptionChanged|PositionChanged $event): void
    {
        $actor = $event->actor;

        if ($actor === null) {
            return;
        }

        $context = $this->context->forUser($actor);

        if ($context->user() === null || $context->isAdmin() || ! $context->has(Role::JefeDepartamento)) {
            return;
        }

        $departments = $this->departmentsOf($event)
            ->filter(fn (string $department): bool => $context->coversDepartment(Role::JefeDepartamento, $department));
        $term = $this->termOf($event) ?? $context->activeTerm()?->getKey();

        if ($departments->isEmpty() || $term === null) {
            return;
        }

        CatalogReview::query()
            ->where('term_id', $term)
            ->whereIn('department_id', $departments)
            ->where('catalog', Catalog::Teachers)
            ->where('status', ReviewStatus::Pending)
            ->get()
            ->filter(fn (CatalogReview $review): bool => CatalogReviewQuery::allowsOnDepartment($context, CatalogValidationPermission::ReviewCorrect, $review->campus_id, $review->department_id))
            ->each(fn (CatalogReview $review) => $this->startReview->handle($review, $actor));
    }

    /**
     * Los departamentos del docente, más el de la adscripción del evento (que llega borrada al quitarla).
     *
     * @return Collection<int, string>
     */
    private function departmentsOf(object $event): Collection
    {
        $teacher = match (true) {
            $event instanceof AvailabilityChanged, $event instanceof TeacherUpdated => $event->teacher,
            $event instanceof QualificationChanged => $event->qualification->teacher,
            $event instanceof ExternalLoadChanged => $event->load->teacher,
            $event instanceof AdscriptionChanged => $event->adscription->teacher,
            $event instanceof PositionChanged => $event->position->teacher,
        };

        return $teacher->adscriptions()->pluck('department_id')
            ->when($event instanceof AdscriptionChanged, fn (Collection $departments) => $departments->push($event->adscription->department_id))
            ->unique()
            ->values();
    }

    private function termOf(object $event): ?string
    {
        return match (true) {
            $event instanceof AvailabilityChanged => $event->term->getKey(),
            $event instanceof ExternalLoadChanged => $event->load->term_id,
            default => null,
        };
    }
}
