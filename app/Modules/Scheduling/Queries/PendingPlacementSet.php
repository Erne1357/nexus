<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Lo que la franja «Por colocar» del editor de horarios muestra (PendingPlacements): de dónde viene («Alternativa A de
 * la solicitud de cambio de SCD-1015 · 5A»), a dónde regresar y, por grupo, cada sesión con el lenguaje de «Corridas
 * del motor»: «Movida» (de dónde a dónde), «Nueva», «Quitada» o «Sin cambio».
 */
final readonly class PendingPlacementSet
{
    /**
     * @param  list<array{group_id: string, label: string, subject: ?string, moves: list<array{tag: string, tone: string, was: ?string, now: ?string}>, same: ?string}>  $groups
     */
    public function __construct(
        public string $title,
        public string $note,
        public ?string $backUrl,
        public array $groups,
    ) {}
}
