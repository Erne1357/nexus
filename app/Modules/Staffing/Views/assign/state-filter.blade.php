{{--
    «Todos · Sin docente · Con docente», con cuántos grupos hay de cada uno en el departamento; el elegido en primary.
    `compact`, en la lista de los dos paneles: letra de 13 px y menos margen, para que quepa en un renglón.
--}}
@php($compact ??= false)
<div role="group" aria-label="Docente" class="join w-full" data-testid="state-filter">
    @foreach (['todos' => 'Todos', 'sin' => 'Sin docente', 'con' => 'Con docente'] as $option => $optionLabel)
        <button type="button" wire:click="$set('state', '{{ $option }}')" aria-pressed="{{ $state === $option ? 'true' : 'false' }}" data-testid="state-{{ $option }}"
            @class(['btn join-item flex-nowrap gap-1.5 whitespace-nowrap max-md:h-11', 'flex-auto px-2 text-[13px]' => $compact, 'flex-1 px-3' => ! $compact, 'btn-primary' => $state === $option])>{{ $optionLabel }} <span class="font-mono text-[13px] tabular-nums opacity-85">{{ $counts[$option] }}</span></button>
    @endforeach
</div>
