{{--
    «Buscar otro docente» bajo el ranking de un grupo (HC-21; staffing::components.teacher-search): quien asigna lo
    ve; el motivo (HC-16) y lo que rechace AssignTeacher salen debajo, porque quien se busca puede no estar en el
    ranking. Pide `$group`.
--}}
@if ($groupWritable)
    <div class="grid gap-3 border-t border-base-300 px-4 py-4" data-testid="search-other-teacher">
        <livewire:staffing::components.teacher-search :group-id="$group->id" wire:key="teacher-search-{{ $group->id }}" />
        @if ($pending !== null && $pending['group'] === $group->id && $pending['from'] === 'busqueda')
            @include('staffing::assign.reason')
        @endif
        @if ($failure !== null && $failure['group'] === $group->id && $failure['from'] === 'busqueda')
            @include('staffing::assign.failure')
        @endif
    </div>
@endif
