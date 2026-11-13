<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use Closure;

/**
 * Arma la condición `whyUnavailable` de una entrada del menú que depende del periodo activo de la credencial
 * (MN-02). El módulo solo responde por un periodo; sin periodo elegido, la entrada no está disponible y el motivo
 * lo da Core, igual para todos (supuesto por confirmar).
 */
final readonly class TermAvailability
{
    public const string NO_TERM = 'No hay un periodo elegido';

    /**
     * @param  Closure(Term): ?string  $forTerm  null si está disponible en el periodo, o el motivo
     * @return Closure(Actor): ?string
     */
    public static function of(Closure $forTerm): Closure
    {
        return static function (Actor $context) use ($forTerm): ?string {
            $term = $context->activeTerm();

            return $term === null ? self::NO_TERM : $forTerm($term);
        };
    }
}
