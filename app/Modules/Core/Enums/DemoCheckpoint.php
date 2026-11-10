<?php

namespace App\Modules\Core\Enums;

use LogicException;

/**
 * Punto del proceso hasta el que se siembra la demo (`php artisan demo:seed --hasta=`). Cada punto es el **inicio** de
 * una etapa de docs/proceso/proceso-propuesto.md: lo anterior queda terminado en todos los departamentos y la etapa
 * recién abierta, sin nada capturado (decisión del usuario, 9 de noviembre de 2026). Su número es el de esa etapa;
 * el 1 es la excepción: catálogos importados y el periodo todavía sin abrir. `All` es la demo mezclada de siempre
 * (`DemoSeeder` o `MassiveSeeder`), no un punto del proceso.
 *
 * Una fase nueva agrega su punto antes de `All` y registra sus pasos y revisiones en el provider de su módulo
 * (`ModuleServiceProvider::demoSteps()` y `demoChecks()`).
 */
enum DemoCheckpoint: string
{
    case Catalogs = 'catalogos';
    case CatalogValidation = 'validacion';
    case Proposal = 'propuesta';
    case Scheduling = 'horarios';
    case Staffing = 'asignacion';
    case Rooms = 'salones';
    case Closing = 'cierre';
    case All = 'todo';

    public function label(): string
    {
        return match ($this) {
            self::Catalogs => 'Catálogos importados',
            self::CatalogValidation => 'Empezando la validación de catálogos',
            self::Proposal => 'Empezando la propuesta por carrera',
            self::Scheduling => 'Empezando horarios y cupos',
            self::Staffing => 'Empezando la asignación docente',
            self::Rooms => 'Empezando salones y acuerdos',
            self::Closing => 'Empezando el cierre',
            self::All => 'Todos los casos',
        };
    }

    /**
     * El número del punto: el de la etapa del proceso que empieza ahí (1 = catálogos importados).
     */
    public function number(): ?int
    {
        $index = array_search($this, self::staged(), true);

        return $index === false ? null : $index + 1;
    }

    /**
     * La etapa del calendario que el punto deja «En curso»; ninguna antes de abrir el periodo ni con la demo mezclada.
     */
    public function stage(): ?Stage
    {
        return match ($this) {
            self::Catalogs, self::All => null,
            self::CatalogValidation => Stage::CatalogValidation,
            self::Proposal => Stage::Proposal,
            self::Scheduling => Stage::Scheduling,
            self::Staffing => Stage::Staffing,
            self::Rooms => Stage::Rooms,
            self::Closing => Stage::Closing,
        };
    }

    /**
     * Los puntos del proceso, en orden.
     *
     * @return list<self>
     */
    public static function staged(): array
    {
        return array_values(array_filter(self::cases(), fn (self $checkpoint): bool => $checkpoint !== self::All));
    }

    /**
     * Los puntos del proceso desde `$first` en adelante, para una revisión que vale de ahí en adelante («abierto desde
     * la validación»).
     *
     * @return list<self>
     */
    public static function since(self $first): array
    {
        $staged = self::staged();
        $index = array_search($first, $staged, true);

        return $index === false ? [] : array_slice($staged, $index);
    }

    /**
     * Los puntos por los que pasa la demo para llegar a `$target`, en orden y con él incluido.
     *
     * @return list<self>
     *
     * @throws LogicException con `All`, que no es un punto del proceso
     */
    public static function upTo(self $target): array
    {
        if ($target === self::All) {
            throw new LogicException('«todo» es la demo mezclada, no un punto del proceso.');
        }

        $staged = self::staged();

        return array_slice($staged, 0, (int) array_search($target, $staged, true) + 1);
    }
}
