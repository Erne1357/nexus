{{--
    «Todos · Sin docente · Con docente», con cuántos grupos hay de cada uno en el departamento; el elegido en primary.
    Es el filtro segmentado de referencia (DESIGN.md, «Filtro segmentado»): un `join` de botones con `aria-pressed`, la
    etiqueta y la cifra en Mono. Nunca desborda su caja: en una caja angosta (debajo de 768 px, y siempre con `compact`,
    en la columna de los dos paneles) son tres columnas iguales con la etiqueta sobre la cifra; desde 768 px, sin
    `compact`, van en un renglón.
--}}
@php($compact ??= false)
<div role="group" aria-label="Docente" @class(['join grid w-full grid-cols-3', 'md:flex md:w-auto' => ! $compact]) data-testid="state-filter">
    @foreach (['todos' => 'Todos', 'sin' => 'Sin docente', 'con' => 'Con docente'] as $option => $optionLabel)
        <button type="button" wire:click="$set('state', '{{ $option }}')" aria-pressed="{{ $state === $option ? 'true' : 'false' }}" data-testid="state-{{ $option }}"
            @class([
                'btn join-item px-2',
                'h-auto min-h-11 min-w-0 flex-col gap-0 py-1 text-[12.5px] leading-tight' => $compact,
                'max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:flex-col max-md:gap-0 max-md:py-1 max-md:leading-tight md:flex-1 md:gap-1.5 md:whitespace-nowrap md:px-3' => ! $compact,
                'btn-primary' => $state === $option,
            ])><span @class(['max-w-full truncate' => $compact, 'max-md:max-w-full max-md:truncate' => ! $compact])>{{ $optionLabel }}</span> <span class="font-mono text-[12.5px] tabular-nums opacity-85">{{ $counts[$option] }}</span></button>
    @endforeach
</div>
