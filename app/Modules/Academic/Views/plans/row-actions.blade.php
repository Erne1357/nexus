{{--
    Acciones de un plan, las mismas en la tabla y en los renglones apilados del celular
    (academic::pages.plans). «Retícula» enlaza a academic.plans.show solo cuando esa ruta existe
    (la crea la tarea 1.8.8). En la tabla (`$inTable`), «Editar» queda solo con su icono entre 768 y
    1199 px y lleva el data-testid del browser test. No hay «Borrar»: un plan se desactiva.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    @if (Route::has('academic.plans.show'))
        <x-button
            icon="o-rectangle-group"
            :link="route('academic.plans.show', $plan)"
            aria-label="Retícula de {{ $plan->code }}"
            class="btn-ghost btn-sm"
        >
            <span @class(['max-[1200px]:hidden' => $inTable])>Retícula</span>
        </x-button>
    @endif

    <x-button
        icon="o-pencil-square"
        wire:click="edit('{{ $plan->id }}')"
        aria-label="Editar {{ $plan->code }}"
        :data-testid="$inTable ? 'edit-'.$plan->code : null"
        class="btn-ghost btn-sm"
        spinner="edit('{{ $plan->id }}')"
    >
        <span @class(['max-[1200px]:hidden' => $inTable])>Editar</span>
    </x-button>

    <x-button
        :label="$plan->is_active ? 'Desactivar' : 'Activar'"
        wire:click="toggleActive('{{ $plan->id }}')"
        wire:confirm="{{ $plan->is_active ? '¿Desactivar el plan '.$plan->code.'?' : '¿Activar el plan '.$plan->code.'?' }}"
        class="btn-ghost btn-sm"
        spinner="toggleActive('{{ $plan->id }}')"
    />
</div>
