<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Events\Concerns\CanBeVetoed;

/**
 * Se va a reabrir una revisión validada (ReopenReview). Evento con veto (docs/arquitectura/arquitectura.md,
 * "Eventos con veto"): se despacha dentro de la transacción, con la revisión ya bloqueada y antes de escribir,
 * para que un módulo de la derecha la bloquee con block(). AcademicLoad la veta si alguna carga del
 * departamento ya salió de borrador (CV5).
 */
final class CatalogReviewReopening
{
    use CanBeVetoed;

    public function __construct(public CatalogReview $review) {}
}
