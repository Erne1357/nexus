{{--
    Renglón de la vista («Por materia · Dos paneles», siempre en el mismo lugar) y, en la vista por materia, los
    filtros; en los dos paneles los filtros van arriba de la lista de grupos.
--}}
<div class="flex flex-wrap items-center gap-x-2.5 gap-y-3 border-b border-base-300 pb-4">
    <div role="group" aria-label="Vista" class="join max-md:w-full">
        <button type="button" wire:click="setView('materia')" aria-pressed="{{ $view === 'materia' ? 'true' : 'false' }}" data-testid="view-subject"
            @class(['btn join-item max-md:h-11 max-md:flex-1', 'btn-primary' => $view === 'materia'])><x-icon name="o-rectangle-stack" class="h-4 w-4" />Por materia</button>
        <button type="button" wire:click="setView('paneles')" aria-pressed="{{ $view === 'paneles' ? 'true' : 'false' }}" data-testid="view-panels"
            @class(['btn join-item max-md:h-11 max-md:flex-1', 'btn-primary' => $view === 'paneles'])><x-icon name="o-view-columns" class="h-4 w-4" />Dos paneles</button>
    </div>

    @if ($view === 'materia')
        <div class="contents [&>*]:w-full md:[&>*]:w-40 min-[1600px]:[&>*]:w-48">
            <div class="md:w-64!">
                <x-input wire:model.live.debounce.300ms="search" placeholder="Grupo, materia o docente" aria-label="Buscar grupo, materia o docente" icon="o-magnifying-glass" clearable data-testid="assign-search" />
            </div>
            <x-select wire:model.live="subjectFilter" aria-label="Materia" :options="$subjects" placeholder="Materia" />
            <x-select wire:model.live="careerFilter" aria-label="Carrera" :options="$careers" placeholder="Carrera" />
            <x-select wire:model.live="semesterFilter" aria-label="Semestre" :options="$semesters" placeholder="Semestre" />
        </div>
        <div class="max-md:w-full">
            @include('staffing::assign.state-filter')
        </div>
        @include('staffing::assign.order-legend', ['class' => 'w-full'])
    @endif
</div>
