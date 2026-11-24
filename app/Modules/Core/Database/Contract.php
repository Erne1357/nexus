<?php

namespace App\Modules\Core\Database;

use Attribute;

/**
 * Marca una migración como contracción (ADR-0033): borra, renombra o cambia de tipo algo que el release anterior
 * ya dejó de usar. Va en un release posterior al que dejó de usarlo, y volver atrás de él exige restaurar el
 * respaldo. `$reason` dice por qué ya es seguro (qué release dejó de usar lo que se borra).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Contract
{
    public function __construct(public string $reason) {}
}
