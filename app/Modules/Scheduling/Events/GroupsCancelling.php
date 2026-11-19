<?php

namespace App\Modules\Scheduling\Events;

/**
 * Unos grupos se van a cancelar (CancelGroup) o la pestaña «Paralelos» pregunta si se podrían cancelar (ParallelsQuery,
 * tarea 8.8). Evento con veto (docs/arquitectura/arquitectura.md, "Eventos con veto") para varios grupos a la vez: se
 * despacha con `event()` dentro de la transacción y antes de escribir, y los módulos de la derecha (Staffing: solicitudes
 * de cambio abiertas) bloquean cada grupo con `block($groupId, 'motivo')`. Una sola pregunta para todos los grupos, así la
 * pestaña no hace una consulta por grupo. No es ShouldDispatchAfterCommit ni se encola.
 */
final class GroupsCancelling
{
    /** @var array<string, list<string>> por id de grupo */
    private array $reasons = [];

    /**
     * @param  array<string, string>  $groups  por id de grupo, cómo se nombra («Cálculo Integral, grupo 3»)
     */
    public function __construct(public array $groups) {}

    public function block(string $groupId, string $reason): void
    {
        $this->reasons[$groupId][] = $reason;
    }

    /**
     * Los motivos por los que el grupo no se cancela, en el orden en que llegaron; vacío si nadie lo bloquea.
     *
     * @return list<string>
     */
    public function reasonsFor(string $groupId): array
    {
        return $this->reasons[$groupId] ?? [];
    }
}
