<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Modality;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se va a desactivar una modalidad (UpdateModality; M11, confirmado el 12 de noviembre de 2026). Evento de solo lectura,
 * hermano de TermOpeningPreviewing: Core no conoce las cargas, así que pregunta y los módulos de la derecha contestan con
 * unpublished() cuántas cargas sin publicar tiene la modalidad en cada periodo abierto. No escriben nada. Se despacha con
 * `event(new …)` dentro de la transacción de la Action.
 */
final class ModalityDeactivating
{
    use Dispatchable;

    /** @var array<string, int> cargas sin publicar por id de periodo */
    private array $unpublished = [];

    public function __construct(public Modality $modality) {}

    /**
     * Dice que el periodo tiene `$count` cargas de la modalidad sin publicar; varios listeners se suman.
     */
    public function unpublished(string $termId, int $count): void
    {
        if ($count > 0) {
            $this->unpublished[$termId] = ($this->unpublished[$termId] ?? 0) + $count;
        }
    }

    /**
     * @return array<string, int>
     */
    public function unpublishedByTerm(): array
    {
        return $this->unpublished;
    }
}
