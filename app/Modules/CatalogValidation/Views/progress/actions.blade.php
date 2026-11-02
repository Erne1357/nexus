{{--
    Las acciones de una fila del tablero de avance, las mismas en la tabla y en los renglones del celular: «Recordar»
    visible, deshabilitado con su motivo si el departamento no tiene nada pendiente o no tiene jefe vigente (DESIGN.md,
    "Nada oculto"), y «Acciones» con «Ver detalle» y «Ver validación».
--}}
@php($department = $row['department'])
@php($reason = match (true) {
    $row['validated'] === $row['total'] => 'No le falta nada por validar.',
    $row['head'] === null => 'No tiene jefe vigente.',
    default => null,
})
<x-button
    icon="o-bell"
    wire:click="remind('{{ $department->id }}')"
    :disabled="$reason !== null"
    :title="$reason"
    class="btn-ghost btn-sm"
    spinner="remind('{{ $department->id }}')"
    data-testid="remind-{{ $department->code }}"
    aria-label="Recordar a {{ $row['head']?->name ?? 'el jefe de '.$department->name }}"
>
    <span @class(['max-[1535px]:hidden' => $inTable])>Recordar</span>
</x-button>
<x-core::row-menu :name="$department->code" :compact="$inTable">
    <x-core::row-menu-item label="Ver detalle" wire:click="open('{{ $department->id }}')" />
    <x-core::row-menu-item label="Ver validación" :href="route('catalog-validation.review', ['department' => $department->id])" data-testid="review-{{ $department->code }}" />
</x-core::row-menu>
