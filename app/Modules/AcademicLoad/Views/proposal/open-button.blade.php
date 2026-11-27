{{--
    El botón que abre la materia en el panel (academic-load::pages.proposal): un lápiz si el usuario puede corregir
    alguna de sus filas, un ojo si todas son de solo lectura (por su campus, M7, o por su estado).
--}}
@php($editable = collect($columns)->contains(fn ($column, $key) => $column['editable'] && isset($row->cells[$key]) && ! isset($locks[$row->cells[$key]->id])))
<button type="button" wire:click="edit('{{ $row->id() }}')" data-opener data-testid="open-{{ $row->subject->code }}" class="btn btn-ghost btn-sm btn-square shrink-0">
    <x-icon :name="$editable ? 'o-pencil-square' : 'o-eye'" class="h-4 w-4" aria-hidden="true" />
    <span class="sr-only">{{ $editable ? 'Editar' : 'Ver' }} <span class="font-mono">{{ $row->subject->code }}</span> en el panel</span>
</button>
