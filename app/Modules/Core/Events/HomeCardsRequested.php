<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\HomeCard;

/**
 * Inicio pide a los módulos las tarjetas de lo que le toca hacer a la persona con su puesto activo, en el periodo activo
 * (por ejemplo, la validación de catálogos abierta). Evento con reporte, de solo lectura (docs/arquitectura/arquitectura.md,
 * «Eventos con reporte»): Core no conoce a los módulos de la derecha; cada uno contesta con add() desde su listener,
 * con sus propias Queries, y no escribe nada. Las tarjetas salen en el orden de los listeners (el de
 * bootstrap/providers.php). Se despacha con `event(new …)` fuera de toda transacción. Sin periodo activo, `term` es nulo.
 */
final class HomeCardsRequested
{
    /** @var list<HomeCard> */
    private array $cards = [];

    public function __construct(public readonly Actor $context, public readonly ?Term $term) {}

    public function add(HomeCard $card): void
    {
        $this->cards[] = $card;
    }

    /**
     * @return list<HomeCard>
     */
    public function cards(): array
    {
        return $this->cards;
    }
}
