{{--
    La oferta de una carrera por campus (#415): un botón por campus del usuario, con el código en Mono y el icono de
    «se ofrece» (`success`) o «no se ofrece» (neutral); al pulsarlo ofrece o deja de ofrecer la carrera en ese
    campus (sin confirmación: es reversible). `$inTable` pone el data-testid de las pruebas de navegador, solo en la tabla. Lo usan la tabla y los renglones apilados de academic::pages.careers.
--}}
<div class="flex flex-wrap gap-1">
    @foreach ($campuses as $campus)
        @php($offered = in_array($campus->id, $career->offered_campus_ids, true))
        <button
            type="button"
            wire:click="toggleOffering('{{ $career->id }}', '{{ $campus->code }}')"
            wire:key="offering-{{ $career->id }}-{{ $campus->code }}"
            wire:loading.attr="disabled"
            aria-pressed="{{ $offered ? 'true' : 'false' }}"
            aria-label="{{ ($offered ? 'Dejar de ofrecer ' : 'Ofrecer ').$career->code.' en '.$campus->code }}"
            title="{{ $offered ? 'Se ofrece en '.$campus->name.'. Pulsa para dejar de ofrecerla.' : 'No se ofrece en '.$campus->name.'. Pulsa para ofrecerla.' }}"
            @if ($inTable) data-testid="offering-{{ $career->code }}-{{ $campus->code }}" @endif
            @class(['btn btn-xs flex-nowrap gap-1 font-mono max-md:h-9', 'btn-success btn-soft' => $offered, 'btn-ghost text-neutral' => ! $offered])
        >
            <x-icon :name="$offered ? 'o-check-circle' : 'o-minus-circle'" class="h-3.5 w-3.5" />{{ $campus->code }}
        </button>
    @endforeach
</div>
