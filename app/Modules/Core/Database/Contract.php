<?php

namespace App\Modules\Core\Database;

use Attribute;

/**
 * Marca una migración destructiva (borra, renombra o cambia de tipo algo que el release anterior aún usa): `reason`
 * dice por qué ya es seguro, es decir, qué release dejó de usarlo. El deployer anota las contracciones pendientes y un
 * rollback que las cruce exige restaurar la base (docs/decisiones, despliegue continuo).
 *
 *     return new #[Contract('el release 1.31 dejó de leer la columna')] class extends Migration { … };
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Contract
{
    public function __construct(public string $reason) {}
}
