{{--
    «Quitar docente» de un grupo con docente (incidencia F-02), junto a su docente en las dos vistas: abre el modal del
    motivo (staffing::assign.unassign-modal). A quien no puede asignar (solo consulta, otro campus o la carga en otro
    estado) le sale deshabilitado con su motivo, como «Asignar» (DESIGN.md, «Nada oculto por permiso»).
--}}
@if ($canAssign)
    <button type="button" wire:click="askUnassign('{{ $group->id }}')" aria-label="Quitar docente: {{ $group->teacher?->name }} del grupo {{ $group->code }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="unassign-teacher">
        <x-icon name="o-user-minus" class="h-4 w-4" />Quitar docente
    </button>
@else
    <span class="inline-flex" title="{{ $readOnlyReason }}">
        <button type="button" disabled aria-label="Quitar docente: {{ $readOnlyReason }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="unassign-disabled"><x-icon name="o-user-minus" class="h-4 w-4" />Quitar docente</button>
    </span>
@endif
