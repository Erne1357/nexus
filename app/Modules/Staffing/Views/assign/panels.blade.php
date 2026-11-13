{{--
    Vista «Dos paneles» (maqueta A): a la izquierda (20rem; 22rem desde 2xl) los grupos del departamento con sus
    filtros, pegados arriba y con su propio desplazamiento desde lg; a la derecha el grupo elegido
    (staffing::assign.group-panel). Los filtros caben en la columna: la rejilla es de una columna `minmax(0, 1fr)`, así
    que la búsqueda y los selects no la ensanchan, y el filtro de estado va en su forma compacta. Debajo de lg la lista
    ocupa el ancho, el elegido no se pinta (no hay panel abierto) y el grupo se abre como hoja a pantalla completa.
--}}
<div class="lg:grid lg:grid-cols-[20rem_minmax(0,1fr)] lg:items-start lg:gap-5 2xl:grid-cols-[22rem_minmax(0,1fr)]" data-testid="assign-panels">
    <section class="min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:sticky lg:top-[calc(var(--shell-top)+1.25rem)] lg:flex lg:max-h-[calc(100dvh-var(--shell-top)-2.5rem)] lg:flex-col"
        aria-label="Grupos del departamento" data-testid="group-list">
        <div class="grid grid-cols-1 gap-2 border-b border-base-300 p-3">
            @include('staffing::assign.state-filter', ['compact' => true])
            <x-input wire:model.live.debounce.300ms="search" placeholder="Grupo, materia o docente" aria-label="Buscar grupo, materia o docente" icon="o-magnifying-glass" :clearable="$search !== ''" data-testid="assign-search" />
            <div class="grid grid-cols-2 gap-2 max-lg:md:grid-cols-3 [&>*:first-child]:col-span-2 max-lg:md:[&>*:first-child]:col-span-1">
                <x-select wire:model.live="subjectFilter" aria-label="Materia" :options="$subjects" placeholder="Materia" />
                <x-select wire:model.live="careerFilter" aria-label="Carrera" :options="$careers" placeholder="Carrera" />
                <x-select wire:model.live="semesterFilter" aria-label="Semestre" :options="$semesters" placeholder="Semestre" />
            </div>
        </div>
        <p class="flex justify-between gap-3 border-b border-base-300 bg-secondary/40 px-4 py-1.5 text-[12.5px] text-secondary-muted" aria-live="polite">
            <span><span class="font-mono tabular-nums">{{ count($visible) }}</span> de <span class="font-mono tabular-nums">{{ $board->total() }}</span> grupos</span>
        </p>
        <ul class="relative divide-y divide-base-300 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain" aria-label="Grupos" data-group-scroll>
            @forelse ($visible as $row)
                @php($group = $row->group)
                @php($isSelected = $selected !== null && $selected->group->id === $group->id)
                <li wire:key="item-{{ $group->id }}">
                    <button type="button" wire:click="pick('{{ $group->id }}')" aria-current="{{ $isSelected ? 'true' : 'false' }}" data-group-item="{{ $group->id }}"
                        class="group grid min-h-15 w-full cursor-pointer grid-cols-[2.75rem_minmax(0,1fr)] items-start gap-x-2.5 px-3.5 py-2.5 text-left hover:bg-base-200 lg:aria-[current=true]:bg-secondary lg:aria-[current=true]:text-secondary-content">
                        <span class="grid justify-items-start gap-1">
                            <span class="font-mono text-[15px] font-semibold leading-tight">{{ $group->code }}</span>
                            @if ($multiCampus && $group->campus !== null)
                                <x-core::campus-tag :campus="$group->campus" />
                            @endif
                        </span>
                        <span class="grid min-w-0 gap-1">
                            <span class="line-clamp-2 font-semibold leading-tight">{{ $group->subject?->name }}</span>
                            <span class="truncate text-[12.5px] text-neutral lg:group-aria-[current=true]:text-secondary-muted">{{ $group->ownerCareer?->code }} · Sem. {{ $group->semester }} · <span class="font-mono">{{ $row->schedule }}</span></span>
                            @if ($row->hasTeacher())
                                <span class="flex min-w-0 items-baseline gap-2 text-[12.5px]">
                                    <x-icon name="o-check-circle" class="h-3.5 w-3.5 shrink-0 self-center text-success" aria-hidden="true" />
                                    <span class="truncate font-medium" title="{{ $group->teacher?->name }}">{{ $group->teacher?->name }}</span>
                                    @if ($row->assignment?->ranking_position_at_assignment !== null)
                                        <span class="ms-auto shrink-0 whitespace-nowrap font-mono text-xs text-neutral lg:group-aria-[current=true]:text-secondary-muted"><span class="sr-only">lugar </span>{{ $row->assignment->ranking_position_at_assignment }} de {{ $row->assignment->ranking_size_at_assignment }}</span>
                                    @endif
                                </span>
                            @else
                                <span><x-core::status-badge tone="warning" label="Sin docente" icon="o-clock" /></span>
                            @endif
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-neutral">
                    {{ $board->total() === 0 ? ($board->campus === null ? 'El departamento no tiene grupos en esta modalidad y periodo.' : "El departamento no tiene grupos en {$board->campus->code}.") : 'Ningún grupo cumple los filtros.' }}
                </li>
            @endforelse
        </ul>
    </section>

    @if ($selected !== null)
        @include('staffing::assign.group-panel', ['row' => $selected])
    @endif
</div>
