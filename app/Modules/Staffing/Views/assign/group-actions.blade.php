{{--
    Las acciones de un grupo en la vista por materia: «Ocultar ranking» si está abierto; si no, «Cambiar» (con docente,
    para quien asigna) o «Ver el ranking completo (N)». A la derecha, «Siguiente sin docente» salvo con el filtro «Sin
    docente», donde el siguiente es el renglón de abajo (ahí va al pie del ranking abierto); `iconOnly`, en el renglón
    compacto de un grupo con docente, solo su icono.
--}}
@php($group = $row->group)
@if ($open)
    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="true" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="toggle-ranking">
        <x-icon name="o-chevron-up" class="h-4 w-4" />Ocultar ranking
    </button>
@elseif ($row->hasTeacher() && $canAssign)
    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="false" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="change-teacher">
        <x-icon name="o-arrows-right-left" class="h-4 w-4" />Cambiar
    </button>
@elseif ($row->rankingSize > 0)
    <button type="button" wire:click="toggleRanking('{{ $group->id }}')" aria-expanded="false" aria-controls="ranking-{{ $group->id }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="toggle-ranking">
        <x-icon name="o-chevron-down" class="h-4 w-4" />Ver el ranking completo ({{ $row->rankingSize }})
    </button>
@endif
@if ($state !== 'sin')
    @include('staffing::assign.next-button', ['from' => $group->id, 'next' => $nextWithoutTeacher[$group->id] ?? null, 'iconOnly' => $iconOnly ?? false])
@endif
