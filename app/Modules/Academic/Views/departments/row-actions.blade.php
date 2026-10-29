{{--
    Acciones de un departamento, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.departments). En la tabla (`$inTable`), «Editar» queda solo con su icono entre
    768 y 1199 px (DESIGN.md, "Responsivo") y lleva el data-testid del browser test; en el celular
    siempre lleva su texto y no repite el data-testid. «Borrar» se deshabilita, con su motivo, si el
    departamento tiene carreras o materias.
--}}
@php($hasDependents = $department->careers_count > 0 || $department->subjects_count > 0)

<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $department->id }}')"
        aria-label="Editar {{ $department->code }}"
        :data-testid="$inTable ? 'edit-'.$department->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $department->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-button
        :label="$department->is_active ? 'Desactivar' : 'Activar'"
        wire:click="toggleActive('{{ $department->id }}')"
        wire:confirm="{{ $department->is_active ? '¿Desactivar el departamento '.$department->code.'?' : '¿Activar el departamento '.$department->code.'?' }}"
        class="btn-ghost btn-sm"
        spinner="toggleActive('{{ $department->id }}')"
    />

    @if ($hasDependents)
        <span @class(['tooltip', 'tooltip-left' => $inTable, 'tooltip-right' => ! $inTable]) data-tip="Solo se borra un departamento sin carreras ni materias.">
            <x-button label="Borrar" class="btn-ghost btn-sm" disabled />
        </span>
    @else
        <x-button
            label="Borrar"
            wire:click="delete('{{ $department->id }}')"
            wire:confirm="¿Borrar el departamento {{ $department->code }}? Dejará de aparecer en el catálogo y se cerrarán los alcances de sus jefes."
            class="btn-ghost btn-sm text-error"
            spinner="delete('{{ $department->id }}')"
        />
    @endif
</div>
