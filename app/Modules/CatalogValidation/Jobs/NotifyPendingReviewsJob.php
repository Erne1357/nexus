<?php

namespace App\Modules\CatalogValidation\Jobs;

use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * El aviso a los jefes de que se abrió la validación de catálogos de un periodo (OpenCatalogValidation, CV9): avisar a
 * los jefes de todos los departamentos tarda, así que va a la cola (CLAUDE.md, §4.7), ya confirmada la transacción
 * que creó las revisiones. Lleva solo ids y valores; manda un aviso por jefe y departamento con los catálogos recién abiertos que le faltan.
 */
final class NotifyPendingReviewsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $campusIds
     * @param  list<string>  $catalogs  los valores de Catalog recién abiertos
     */
    public function __construct(public readonly string $termId, public readonly array $campusIds, public readonly array $catalogs) {}

    public function handle(NotifyPendingReviews $notify): void
    {
        $term = Term::query()->find($this->termId);

        if ($term === null) {
            return;
        }

        $notify->notifyOpened(
            $term,
            Campus::query()->whereIn('id', $this->campusIds)->orderBy('code')->get(),
            array_values(array_filter(array_map(fn (string $value): ?Catalog => Catalog::tryFrom($value), $this->catalogs))),
        );
    }
}
