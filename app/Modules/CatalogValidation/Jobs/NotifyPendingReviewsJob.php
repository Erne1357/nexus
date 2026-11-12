<?php

namespace App\Modules\CatalogValidation\Jobs;

use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * El aviso a los jefes de que se abrió la validación de catálogos de un periodo (OpenCatalogValidation, CV9): avisar a
 * los jefes de todos los departamentos tarda, así que va a la cola (CLAUDE.md, §4.7), ya confirmada la transacción
 * que creó las revisiones. Lleva solo ids; a cada jefe solo le avisa de lo que tiene abierto y sin validar.
 */
final class NotifyPendingReviewsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $campusIds
     */
    public function __construct(public readonly string $termId, public readonly array $campusIds) {}

    public function handle(NotifyPendingReviews $notify): void
    {
        $term = Term::query()->find($this->termId);

        if ($term === null) {
            return;
        }

        foreach (Campus::query()->whereIn('id', $this->campusIds)->orderBy('code')->get() as $campus) {
            $notify->handle($term, $campus);
        }
    }
}
