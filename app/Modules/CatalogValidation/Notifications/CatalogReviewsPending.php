<?php

namespace App\Modules\CatalogValidation\Notifications;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;

/**
 * «Tienes catálogos por validar» (tarea 2.9): al jefe de un departamento, con los catálogos que le faltan
 * del periodo en el campus. Lleva a la validación de catálogos de ese departamento (tarea 2.4), y la
 * credencial queda en el periodo y el campus del aviso. Deja de poder abrirse si el departamento se borró o
 * el usuario ya no es su jefe.
 */
final class CatalogReviewsPending extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'catalog-reviews-pending';
    }

    public static function typeLabel(): string
    {
        return 'Catálogos por validar';
    }

    /**
     * @param  list<Catalog>  $pending
     */
    public function __construct(
        private readonly Term $term,
        private readonly Campus $campus,
        private readonly Department $department,
        private readonly array $pending,
    ) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Tienes catálogos por validar',
            body: "{$this->term->name} · {$this->department->name}: {$this->catalogList()}",
            icon: 'o-clipboard-document-check',
            route: 'catalog-validation.review',
            parameters: ['department' => $this->department->getKey()],
            termId: $this->term->getKey(),
            campusId: $this->campus->getKey(),
            tone: 'warning',
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $department = Department::query()->find($message->parameters['department'] ?? null);

        if ($department === null) {
            return 'Ese departamento ya no existe.';
        }

        if ($message->campusId !== null && ! $context->allows(CatalogValidationPermission::ReviewValidate, Target::of(campus: $message->campusId, unit: $department->getKey()))) {
            return "Ya no eres jefe de {$department->name}.";
        }

        return parent::unavailableReason($context, $message);
    }

    /**
     * «docentes, materias, planes de estudio, carreras y salones»: los catálogos en el orden del enum, con los salones al
     * final.
     */
    private function catalogList(): string
    {
        $ordered = [...array_filter($this->pending, fn (Catalog $catalog): bool => $catalog !== Catalog::Rooms), ...array_filter($this->pending, fn (Catalog $catalog): bool => $catalog === Catalog::Rooms)];
        $names = array_map(fn (Catalog $catalog): string => mb_strtolower($catalog->label()), $ordered);

        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' y '.$last;
    }
}
