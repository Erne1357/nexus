<?php

namespace App\Modules\Core\Authorization;

/**
 * Sobre qué se pide un permiso: los valores por dimensión (campus, unidad, carrera) del registro
 * evaluado, o el dueño si el permiso es personal. Un valor nulo es «sin valor»: lo general.
 * Inmutable.
 */
final readonly class Target
{
    /**
     * @param  array<string, list<string>>  $dimensions  valores por `Dimension->value`
     */
    private function __construct(private array $dimensions, public ?string $ownerId = null) {}

    /**
     * @param  string|list<string>|null  $campus
     * @param  string|list<string>|null  $unit
     * @param  string|list<string>|null  $career
     */
    public static function of(string|array|null $campus = null, string|array|null $unit = null, string|array|null $career = null): self
    {
        $dimensions = [];

        foreach ([Dimension::Campus->value => $campus, Dimension::Unit->value => $unit, Dimension::Career->value => $career] as $name => $value) {
            if ($value !== null) {
                $dimensions[$name] = array_values((array) $value);
            }
        }

        return new self($dimensions);
    }

    public static function ownedBy(string $userId): self
    {
        return new self([], $userId);
    }

    /**
     * Los valores del objetivo en la dimensión; null si no tiene (lo general).
     *
     * @return list<string>|null
     */
    public function values(Dimension $dimension): ?array
    {
        return $this->dimensions[$dimension->value] ?? null;
    }
}
