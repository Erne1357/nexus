<?php

namespace App\Modules\CatalogValidation\Notifications;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;

/**
 * «Se resolvió tu observación» (CV10, confirmado el 14 de noviembre de 2026): al jefe que puso una observación,
 * cuando alguien más la marca como resuelta, con el registro, el tipo de la observación y la nota de lo que se hizo (o
 * quién la resolvió, si no dejó nota). Solo va a la campana. Lleva a la revisión de ese catálogo del departamento (o de
 * Servicios Escolares, CV15), en el periodo de la revisión. Deja de poder abrirse si el departamento se borró o el usuario ya no ve sus revisiones.
 */
final class CatalogReviewFlagResolvedNotice extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'catalog-review-flag-resolved';
    }

    public static function typeLabel(): string
    {
        return 'Observación resuelta';
    }

    public function __construct(private readonly CatalogReviewFlag $flag) {}

    public function message(object $notifiable): NotificationMessage
    {
        $flag = $this->flag->loadMissing(['review', 'flaggable', 'resolver']);
        $about = 'Sobre '.ValidationProgressQuery::recordLabel($flag->review->catalog, $flag->flaggable)." («{$flag->flag->label()}»)";

        return new NotificationMessage(
            title: 'Se resolvió tu observación',
            body: $flag->resolution_note !== null
                ? "{$about}: {$flag->resolution_note}"
                : "{$about}. La resolvió ".($flag->resolver?->name ?? 'un usuario ya dado de baja').', sin nota.',
            icon: 'o-check-circle',
            route: 'catalog-validation.review',
            parameters: ['department' => $flag->review->owner_id, 'catalogo' => $flag->review->catalog->value],
            termId: $flag->review->term_id,
            tone: 'success',
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $owner = $message->parameters['department'] ?? null;

        if ($owner === ReviewOwner::SCHOOL_SERVICES) {
            return CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewView, $owner)
                ? parent::unavailableReason($context, $message)
                : 'Ya no eres jefe de Servicios Escolares.';
        }

        $department = KeyLookup::find(Department::query(), $owner);

        if ($department === null) {
            return 'Ese departamento ya no existe.';
        }

        if (! CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewView, $department->getKey())) {
            return "Ya no eres jefe de {$department->name}.";
        }

        return parent::unavailableReason($context, $message);
    }
}
