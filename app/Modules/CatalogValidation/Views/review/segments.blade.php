{{--
    El filtro segmentado de la revisión (DESIGN.md, «Filtro segmentado» y «Revisión por registro»): Por revisar ·
    Revisados · Con observación · Todos, cada uno con su cifra en Mono, `aria-pressed` y el elegido en `btn-primary`.
    Fija `segment` (`?revision=`). Desde 768 px es un `join` de 40 px; más angosto, cuatro columnas iguales de 44 px con
    la etiqueta sobre la cifra, para que nunca desborde. `$segments` y `$segment` son de review.php.
--}}
<div role="group" aria-label="Revisión" class="grid w-full grid-cols-4 md:join md:flex md:w-auto" data-testid="filter-segment">
    @foreach ($segments as $option)
        @php($pressed = $segment === $option['id'])
        <button type="button" wire:click="$set('segment', '{{ $option['id'] }}')" aria-pressed="{{ $pressed ? 'true' : 'false' }}" data-testid="segment-{{ $option['id'] }}"
            @class([
                'btn join-item h-auto min-h-11 min-w-0 flex-col gap-0 rounded-none px-1.5 py-1 text-[12.5px] leading-tight font-medium first:rounded-s-field last:rounded-e-field md:h-10 md:min-h-10 md:flex-row md:flex-none md:gap-1.5 md:whitespace-nowrap md:px-3 md:text-sm',
                'btn-primary' => $pressed,
                'border-base-300 bg-base-100' => ! $pressed,
            ])>
            {{ $option['label'] }}
            <span class="font-mono text-[12.5px] tabular-nums opacity-85">{{ $option['count'] }}</span>
        </button>
    @endforeach
</div>
