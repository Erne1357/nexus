{{--
    «Quitar docente» de un grupo con docente (incidencia F-02), junto a su docente en las dos vistas: abre el modal del
    motivo (staffing::assign.unassign-modal). Se comporta como «Cambiar»: solo lo ve quien asigna en el grupo; a quien
    solo consulta (otro rol, otro campus o la carga en otro estado) no le sale, así una carga cerrada o publicada no se
    llena de botones deshabilitados (decisión del coordinador del Riel C, 19 de noviembre de 2026).
--}}
@if ($canAssign)
    <button type="button" wire:click="askUnassign('{{ $group->id }}')" aria-label="Quitar docente: {{ $group->teacher?->name }} del grupo {{ $group->code }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="unassign-teacher">
        <x-icon name="o-user-minus" class="h-4 w-4" />Quitar docente
    </button>
@endif
