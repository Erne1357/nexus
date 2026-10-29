{{--
    Acciones de una carrera, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.careers). En la tabla (`$inTable`), «Editar» queda solo con su icono entre 768 y
    1199 px (DESIGN.md, "Responsivo") y lleva el data-testid del browser test; en el celular siempre
    lleva su texto y no repite el data-testid. «Ofrecer» y «Dejar de ofrecer» actúan sobre el campus
    activo (sin confirmación: es reversible); sin uno, lo piden en lugar de la acción. «Borrar» se deshabilita, con su motivo, si la
    carrera tiene planes.
--}}
<div @class(['flex flex-wrap gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $career->id }}')"
        aria-label="Editar {{ $career->code }}"
        :data-testid="$inTable ? 'edit-'.$career->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $career->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    @if ($campus === null)
        <span class="self-center px-2 text-sm text-neutral">Elige un campus en tu credencial</span>
    @else
        <x-button
            :label="$career->offered_here ? 'Dejar de ofrecer' : 'Ofrecer'"
            wire:click="toggleOffering('{{ $career->id }}')"
            :data-testid="$inTable ? 'offering-'.$career->code : null"
            class="btn-ghost btn-sm"
            spinner="toggleOffering('{{ $career->id }}')"
        />
    @endif

    <x-button
        :label="$career->is_active ? 'Desactivar' : 'Activar'"
        wire:click="toggleActive('{{ $career->id }}')"
        wire:confirm="{{ $career->is_active ? '¿Desactivar la carrera '.$career->code.'?' : '¿Activar la carrera '.$career->code.'?' }}"
        class="btn-ghost btn-sm"
        spinner="toggleActive('{{ $career->id }}')"
    />

    @if ($career->study_plans_count > 0)
        <span @class(['tooltip', 'tooltip-left' => $inTable, 'tooltip-right' => ! $inTable]) data-tip="Solo se borra una carrera sin planes de estudio.">
            <x-button label="Borrar" class="btn-ghost btn-sm" disabled />
        </span>
    @else
        <x-button
            label="Borrar"
            wire:click="delete('{{ $career->id }}')"
            wire:confirm="¿Borrar la carrera {{ $career->code }}? Dejará de aparecer en el catálogo y se cerrarán los alcances de sus coordinadores."
            class="btn-ghost btn-sm text-error"
            spinner="delete('{{ $career->id }}')"
        />
    @endif
</div>
