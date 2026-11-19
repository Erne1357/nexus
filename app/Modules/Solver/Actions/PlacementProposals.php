<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Enums\PlacementStop;
use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * Lo que devuelve ProposePlacements: las alternativas (salidas del contrato, en el orden en que salieron) y por qué dejó
 * de buscar. Se recorre y se cuenta como la lista de alternativas.
 *
 * @implements IteratorAggregate<int, SolverOutput>
 */
final readonly class PlacementProposals implements Countable, IteratorAggregate
{
    /**
     * @param  list<SolverOutput>  $alternatives
     */
    public function __construct(public array $alternatives, public PlacementStop $stop) {}

    public function count(): int
    {
        return count($this->alternatives);
    }

    /**
     * @return ArrayIterator<int, SolverOutput>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->alternatives);
    }

    /**
     * El motivo para la pantalla; nulo si encontró todas las que se pidieron.
     */
    public function message(): ?string
    {
        return $this->stop->message(count($this->alternatives));
    }
}
