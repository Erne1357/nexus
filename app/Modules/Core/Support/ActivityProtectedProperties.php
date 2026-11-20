<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\ModuleServiceProvider;

/**
 * Las propiedades de la bitácora (CF-S5) que solo ve quien tiene un permiso: lo reservado de una entrada no sale en su
 * detalle a quien no lo tiene, aunque vea la bitácora (el directivo, AZ-03). Cada módulo declara las de sus eventos en
 * `activityProtectedProperties()` de su provider: `evento => [propiedad => permiso]`. Se quita en la lectura, nunca en lo
 * guardado (HC-18). Si dos módulos declaran la misma propiedad, se piden todos sus permisos. Inmutable: se comparte
 * entre peticiones; quién mira lo dice el Actor de cada llamada.
 *
 * Hoy (confirmado el 20 de noviembre de 2026): el motivo de salida de un docente (D8, `discharged`, `reason`) lo ven
 * quienes dan de baja docentes, el admin y la Subdirección; la nota libre de la baja de una cuenta (`deactivated`,
 * `note`), solo quien da de baja cuentas, el admin. Su motivo (Renuncia, Jubilación…) sí sale.
 */
final readonly class ActivityProtectedProperties
{
    /**
     * @param  array<string, array<string, list<Permission>>>  $declared  evento => propiedad => permisos
     */
    public function __construct(private array $declared) {}

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $declared = [];

        foreach ($modules as $module) {
            foreach ($module->activityProtectedProperties() as $event => $properties) {
                foreach ($properties as $property => $permission) {
                    $declared[$event][$property][] = $permission;
                }
            }
        }

        return new self($declared);
    }

    /**
     * Las propiedades de una entrada del evento `$event` que puede ver quien mira: sin las reservadas cuyo permiso no
     * tiene en ningún alcance (`allowsAny`).
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function visibleTo(Actor $viewer, ?string $event, array $properties): array
    {
        foreach ($this->declared[$event ?? ''] ?? [] as $property => $permissions) {
            if (array_key_exists($property, $properties) && ! array_all($permissions, fn (Permission $permission): bool => $viewer->allowsAny($permission))) {
                unset($properties[$property]);
            }
        }

        return $properties;
    }
}
