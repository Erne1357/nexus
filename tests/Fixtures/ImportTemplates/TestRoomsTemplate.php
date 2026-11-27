<?php

namespace Tests\Fixtures\ImportTemplates;

use App\Modules\Imports\Templates\ImportTemplate;

/**
 * Plantilla de prueba de un catálogo de un campus (TestRoom), como será `rooms`: la clave natural
 * incluye el campus del lote y "desactivar faltantes" solo mira ese campus.
 */
class TestRoomsTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'test-rooms';
    }

    public function label(): string
    {
        return 'Salones (prueba)';
    }

    public function fields(): array
    {
        return ['code' => 'clave', 'name' => 'nombre'];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string'], 'name' => ['required', 'string']];
    }

    public function model(): string
    {
        return TestRoom::class;
    }

    public function requiresCampus(): bool
    {
        return true;
    }
}
