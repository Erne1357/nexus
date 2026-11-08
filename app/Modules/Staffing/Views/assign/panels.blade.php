{{--
    Vista «Dos paneles» (maqueta A): a la izquierda los grupos del departamento con sus filtros, pegados arriba y con su
    propio desplazamiento desde lg; a la derecha el grupo elegido (staffing::assign.group-panel). Debajo de lg la lista
    ocupa el ancho y el grupo elegido se abre como hoja a pantalla completa.
--}}
<div class="lg:grid lg:grid-cols-[minmax(17rem,19rem)_minmax(0,1fr)] lg:items-start lg:gap-5 min-[1600px]:grid-cols-[22rem_minmax(0,1fr)]" data-testid="assign-panels">
    <section class="min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:sticky lg:top-[calc(var(--shell-top)+1.25rem)] lg:flex lg:max-h-[calc(100dvh-var(--shell-top)-2.5rem)] lg:flex-col"
        aria-label="Grupos del departamento" data-testid="group-list">
        <div class="grid gap-2 border-b border-base-300 p-3">
            @include('staffing::assign.state-filter')
            <x-input wire:model.live.debounce.300ms="search" placeholder="Grupo, materia o docente" aria-label="Buscar grupo, materia o docente" icon="o-magnifying-glass" clearable data-testid="assign-search" />
            <div class="grid grid-cols-2 gap-2 max-lg:md:grid-cols-3 [&>*:first-child]:col-span-2 max-lg:md:[&>*:first-child]:col-span-1">
                <x-select wire:model.live="subjectFilter" aria-label="Materia" :options="$subjects" placeholder="Materia" />
                <x-select wire:model.live="careerFilter" aria-label="Carrera" :options="$careers" placeholder="Carrera" />
                <x-select wire:model.live="semesterFilter" aria-label="Semestre" :options="$semesters" placeholder="Semestre" />
            </div>
        </div>
        <p class="flex justify-between gap-3 border-b border-base-300 bg-secondary/40 px-4 py-1.5 text-[12.5px] text-secondary-muted" aria-live="polite">
            <span><span class="font-mono tabular-nums">{{ count($visible) }}</span> de <span class="font-mono tabular-nums">{{ $board->total() }}</span> grupos</span>
        </p>
        <ul class="divide-y divide-base-300 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain" aria-label="Grupos">
            @forelse ($visible as $row)
                @php($group = $row->group)
                @php($isSelected = $selected !== null && $selected->group->id === $group->id)
                <li wire:key="item-{{ $group->id }}">
                    <button type="button" wire:click="pick('{{ $group->id }}')" aria-current="{{ $isSelected ? 'true' : 'false' }}" data-group-item="{{ $group->id }}"
                        class="group grid min-h-15 w-full cursor-pointer grid-cols-[2.75rem_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 px-3.5 py-2.5 text-left hover:bg-base-200 aria-[current=true]:bg-secondary aria-[current=true]:text-secondary-content md:grid-cols-[2.75rem_minmax(0,1fr)_auto]">
                        <span class="font-mono text-[15px] font-semibold">{{ $group->code }}</span>
                        <span class="grid min-w-0 gap-0.5">
                            <span class="line-clamp-2 font-semibold leading-tight">{{ $group->subject?->name }}</span>
                            <span class="truncate text-[12.5px] text-neutral group-aria-[current=true]:text-secondary-muted">{{ $group->ownerCareer?->code }} · Sem. {{ $group->semester }} · <span class="font-mono">{{ $row->schedule }}</span></span>
                        </span>
                        <span class="col-start-2 flex min-w-0 items-center gap-2 text-[13px] md:col-start-3 md:max-w-36 md:flex-col md:items-end md:gap-0 md:text-right">
                            @if ($row->hasTeacher())
                                <span class="truncate font-medium" title="{{ $group->teacher?->name }}">{{ $group->teacher?->name }}</span>
                                @if ($row->assignment?->ranking_position_at_assignment !== null)
                                    <span class="whitespace-nowrap font-mono text-xs text-neutral group-aria-[current=true]:text-secondary-muted">lugar {{ $row->assignment->ranking_position_at_assignment }} de {{ $row->assignment->ranking_size_at_assignment }}</span>
                                @endif
                            @else
                                <x-core::status-badge tone="warning" label="Sin docente" icon="o-clock" />
                            @endif
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-neutral">
                    {{ $board->total() === 0 ? 'El departamento no tiene grupos en este periodo y campus.' : 'Ningún grupo cumple los filtros.' }}
                </li>
            @endforelse
        </ul>
    </section>

    @if ($selected !== null)
        @include('staffing::assign.group-panel', ['row' => $selected])
    @endif
</div>
