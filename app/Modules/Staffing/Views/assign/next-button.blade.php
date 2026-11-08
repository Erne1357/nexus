{{--
    «Siguiente sin docente»: en la vista por materia lleva la página al grupo (sin pedir nada al servidor) y lo marca un
    momento; en los dos paneles (`pick`) lo elige. Sin otro grupo sin docente a la vista no aparece.
--}}
@php($pick ??= false)
@if ($next !== null)
    <button type="button" data-testid="next-without-teacher" data-from="{{ $from }}" data-next-group="{{ $next }}"
        @if ($pick) wire:click="pick('{{ $next }}')" @else x-on:click="$dispatch('assign-scroll-to', { id: '{{ $next }}', focus: true })" @endif
        @class(['btn btn-ghost btn-sm max-md:h-11', 'ms-auto' => ! $pick])><x-icon :name="$pick ? 'o-arrow-right' : 'o-arrow-down'" class="h-4 w-4" />Siguiente sin docente</button>
@endif
