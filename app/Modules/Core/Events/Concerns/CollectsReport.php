<?php

namespace App\Modules\Core\Events\Concerns;

/**
 * Para un evento con reporte (docs/arquitectura/arquitectura.md, "Eventos con reporte"): la Action lo
 * despacha dentro de su transacción y los listeners de los módulos de la derecha del mapa, que hacen su
 * parte en la misma transacción, dicen qué hicieron con una línea (`12 cargas académicas nuevas`). La
 * Action lee lines() para su bitácora y para lo que muestra la pantalla.
 *
 * Es de Core porque cualquier módulo puede necesitarlo. Como el de veto (CanBeVetoed), un evento con
 * reporte no es ShouldDispatchAfterCommit ni se encola: los listeners corren en la misma petición.
 */
trait CollectsReport
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * Agrega una línea al reporte; varios listeners juntan las suyas.
     */
    public function report(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * Las líneas del reporte, en el orden en que llegaron.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
