{{--
    Acciones de un campus, las mismas en la tabla y en los renglones apilados del celular
    (core::pages.campuses). En la tabla (`$inTable`), «Editar» queda solo con su icono entre 768 y
    1199 px (DESIGN.md, "Responsivo") y lleva el data-testid del browser test; en el celular siempre
    lleva su texto y no repite el data-testid. Lo que el rol no permite se muestra deshabilitado con su
    motivo.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $campus->id }}')"
        aria-label="Editar {{ $campus->code }}"
        :data-testid="$inTable ? 'edit-'.$campus->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $campus->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    @can('update', $campus)
        <x-button
            :label="$campus->is_active ? 'Desactivar' : 'Activar'"
            wire:click="toggleActive('{{ $campus->id }}')"
            wire:confirm="{{ $campus->is_active ? '¿Desactivar el campus '.$campus->code.'? Nadie podrá trabajar en él hasta que se reactive.' : '¿Activar el campus '.$campus->code.'?' }}"
            class="btn-ghost btn-sm"
            spinner="toggleActive('{{ $campus->id }}')"
        />
    @else
        <span @class(['tooltip', 'tooltip-left' => $inTable, 'tooltip-right' => ! $inTable]) data-tip="Solo el administrador activa o desactiva campus.">
            <x-button :label="$campus->is_active ? 'Desactivar' : 'Activar'" class="btn-ghost btn-sm" disabled />
        </span>
    @endcan
</div>
