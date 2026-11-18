<?php

namespace App\Modules\AcademicLoad\Queries;

/**
 * Una celda del tablero «Demanda por materia» (tarea 5.7): grupos sugeridos por la proyección y grupos que cuentan como
 * pedidos, de una carrera o de un total. `pending` es la celda de una carrera sin propuesta enviada, que cuenta lo
 * sugerido (DemandProposalState); `large`, si lo pedido se aparta de lo sugerido lo bastante para resaltarlo
 * (DemandSettings::isLargeDifference). Una celda pendiente nunca se resalta.
 */
final readonly class DemandBoardCell
{
    public function __construct(
        public int $suggested,
        public int $counted,
        public bool $pending = false,
        public bool $large = false,
    ) {}

    /**
     * Lo pedido menos lo sugerido.
     */
    public function difference(): int
    {
        return $this->counted - $this->suggested;
    }

    /**
     * La diferencia en porcentaje de lo sugerido, redondeada; null sin nada sugerido.
     */
    public function percent(): ?int
    {
        return $this->suggested === 0 ? null : (int) round($this->difference() * 100 / $this->suggested);
    }
}
