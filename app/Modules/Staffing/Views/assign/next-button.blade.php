{{--
    «Siguiente sin docente»: en la vista por materia lleva la página al grupo (sin pedir nada al servidor) y lo marca un
    momento; en los dos paneles (`pick`) lo elige, y en celular dice «Siguiente». `iconOnly` (el renglón compacto de un
    grupo con docente): solo la flecha, con el nombre en `aria-label` y `title`. Sin otro grupo sin docente a la vista
    no aparece.
--}}
@php($pick ??= false)
@php($iconOnly ??= false)
@if ($next !== null)
    <button type="button" data-testid="next-without-teacher" data-from="{{ $from }}" data-next-group="{{ $next }}"
        @if ($pick) wire:click="pick('{{ $next }}')" @else x-on:click="$dispatch('assign-scroll-to', { id: '{{ $next }}', focus: true })" @endif
        @if ($iconOnly) aria-label="Siguiente sin docente" title="Siguiente sin docente" @endif
        @class(['btn btn-ghost btn-sm max-md:h-11', 'btn-square max-md:w-11' => $iconOnly, 'ms-auto' => ! $pick && ! $iconOnly])><x-icon :name="$pick ? 'o-arrow-right' : 'o-arrow-down'" class="h-4 w-4" />@unless ($iconOnly)Siguiente<span @class(['max-sm:sr-only' => $pick])> sin docente</span>@endunless</button>
@endif
