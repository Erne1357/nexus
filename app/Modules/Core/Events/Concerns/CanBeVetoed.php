<?php

namespace App\Modules\Core\Events\Concerns;

/**
 * Para un evento con veto (docs/arquitectura/arquitectura.md, "Eventos con veto"): la Action lo
 * despacha dentro de su transacción, antes de escribir, y los listeners de los módulos de la derecha
 * del mapa que dependan del registro lo bloquean con un motivo. La Action lee blocked() y, si hay
 * veto, rechaza con reasons().
 *
 * Es de Core porque cualquier módulo puede necesitarlo. Un evento con veto no es
 * ShouldDispatchAfterCommit ni se encola: los listeners deben contestar en la misma petición.
 */
trait CanBeVetoed
{
    /** @var list<string> */
    private array $reasons = [];

    /**
     * Bloquea la operación; varios bloqueos juntan sus motivos.
     */
    public function block(string $reason): void
    {
        $this->reasons[] = $reason;
    }

    public function blocked(): bool
    {
        return $this->reasons !== [];
    }

    /**
     * Los motivos de los bloqueos, en el orden en que llegaron.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
