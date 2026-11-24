<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Queries\CampusMapQuery;
use Livewire\Component;

/**
 * Selector de salón sobre el mapa 3D del campus (tarea 14 de la orden de selección, diseño §6.5). Es el mismo mapa del
 * Inicio (CampusMapQuery del periodo de consulta, ADR-0030) en modo «elegir»: `enablePick` resalta los salones
 * elegibles, apaga los demás con su motivo y, al tocar uno elegible, emite el evento de ventana `room-picked` con
 * `{key}` (la `map_key` del salón). No sabe nada de quien lo usa: solo recibe llaves y motivos.
 *
 * Props: `eligible` (lista de map_key), `rejected` (map_key => motivo) y, opcional, `campusId` (hoy el mapa tiene un
 * solo campus, el de los edificios con map_key; se acepta para cuando haya más). Se leen al montar (no son reactivas: el JSON del mapa pesa ~865 KB y no se reenvía): monta el selector solo
 * mientras se ve y con `wire:key` por grupo. Una sola instancia por página (el mapa usa ids fijos).
 */
new class extends Component
{
    /** @var list<string> */
    public array $eligible = [];

    /** @var array<string, string> */
    public array $rejected = [];

    public ?string $campusId = null;

    public function with(CampusMapQuery $query, Actor $context): array
    {
        return ['map' => $query->forTerm($context->viewTerm())];
    }
};
