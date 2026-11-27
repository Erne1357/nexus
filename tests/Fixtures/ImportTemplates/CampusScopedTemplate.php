<?php

namespace Tests\Fixtures\ImportTemplates;

/**
 * La plantilla de prueba como si su catálogo fuera de un campus (como `rooms`): la clave natural
 * incluye el campus del lote.
 */
class CampusScopedTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-campus-scoped';
    }

    public function requiresCampus(): bool
    {
        return true;
    }
}
