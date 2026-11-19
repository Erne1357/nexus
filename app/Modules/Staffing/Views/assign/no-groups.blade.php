{{--
    Por qué no hay grupos a la vista, igual en las dos vistas: el departamento no tiene grupos (en la modalidad o en el
    campus del filtro), o los filtros no dejan ninguno. Si el que los deja fuera es el filtro de docente («Sin docente» o
    «Con docente», `hiddenByState` grupos cumplen los demás), lo dice y ofrece «Ver todos», que lo quita (F-09).
--}}
@if ($board->total() === 0)
    {{ $board->campus === null ? 'El departamento no tiene grupos en esta modalidad y periodo.' : "El departamento no tiene grupos en {$board->campus->code}." }}
@elseif ($hiddenByState > 0)
    <span class="grid justify-items-center gap-3">
        <span>
            @if ($hiddenByState === 1)
                El grupo que cumple los filtros {{ $state === 'sin' ? 'ya tiene docente.' : 'no tiene docente.' }}
            @else
                Los <span class="font-mono tabular-nums">{{ $hiddenByState }}</span> grupos que cumplen los filtros {{ $state === 'sin' ? 'ya tienen docente.' : 'no tienen docente.' }}
            @endif
        </span>
        <button type="button" wire:click="showAllStates" class="btn btn-primary btn-soft btn-sm max-md:h-11" data-testid="show-all-states">
            <x-icon name="o-eye" class="h-4 w-4" />Ver todos
        </button>
    </span>
@else
    Ningún grupo cumple los filtros.
@endif
