<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se está por abrir (o completar la apertura de) un periodo en una modalidad y la pantalla quiere decir qué se creará
 * (TermQuery::openingPreview(); tarea 14). Evento con reporte, pero de solo lectura: los módulos de la derecha, que Core
 * no conoce, contestan con add() lo que TermOpened crearía y no escriben nada. Se despacha con `event(new …)`, como los
 * demás (docs/arquitectura/arquitectura.md, «Eventos con reporte»).
 */
final class TermOpeningPreviewing
{
    use Dispatchable;

    /** @var list<array{count: int, text: string, chips: list<string>}> */
    private array $items = [];

    public function __construct(public Term $term, public Modality $modality) {}

    /**
     * Agrega lo que un módulo crearía: cuántos, una frase que lo explica y, si hay, las claves que abarca (chips).
     *
     * @param  list<string>  $chips
     */
    public function add(int $count, string $text, array $chips = []): void
    {
        $this->items[] = ['count' => $count, 'text' => $text, 'chips' => $chips];
    }

    /**
     * @return list<array{count: int, text: string, chips: list<string>}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
