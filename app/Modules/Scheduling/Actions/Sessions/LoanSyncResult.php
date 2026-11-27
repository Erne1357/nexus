<?php

namespace App\Modules\Scheduling\Actions\Sessions;

/**
 * Lo que hizo SyncSessionLoans: si pidió algún préstamo y si alguno no se pudo pedir porque el préstamo vivo de otro
 * grupo ya aparta el salón a esa hora (la sesión queda con HC-10 «sin préstamo»). `unavailable`: con los préstamos de salón
 * apagados (tarea 1.53) quitó el salón a una sesión cuyo hueco nuevo pediría un préstamo.
 */
final readonly class LoanSyncResult
{
    public function __construct(
        public bool $requested = false,
        public bool $blocked = false,
        public bool $unavailable = false,
    ) {}

    /**
     * Lo de las dos sincronías juntas (el salón de antes y el nuevo).
     */
    public function and(self $other): self
    {
        return new self($this->requested || $other->requested, $this->blocked || $other->blocked, $this->unavailable || $other->unavailable);
    }
}
