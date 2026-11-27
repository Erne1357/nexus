{{--
    Carga actual (tarea 1.57, SEL-18; maqueta 1-carga-b, variante B «agrupada por materia»): encabezado con el
    departamento, la modalidad y el periodo y, a la derecha, la carga con su estado y cuántos grupos tienen docente;
    filtro de campus, búsqueda, carrera y filtros rápidos con su cuenta; y la lista por materia con el tipo de espacio de
    la materia (control segmentado) y, por grupo, sus sesiones con salón, sus avisos, el cupo en un campo y el docente.
    Todo sale de CurrentLoadQuery. En celular cada grupo es una tarjeta apilada.
--}}
<div class="space-y-5" data-testid="current-load-page">
    @if ($board === null)
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Carga actual</h1>
        <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality?->code ?? $modalidad" class="w-full md:w-56" />
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="current-load-empty">{{ $message }}</div>
    @else
        @php($total = $board->total())
        @php($withTeacher = $board->withTeacher())
        <div class="flex flex-wrap items-end justify-between gap-x-7 gap-y-4">
            <div class="min-w-0 max-md:w-full">
                <h1 class="text-2xl font-bold leading-tight md:text-3xl">Carga actual</h1>
                <p class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-neutral" data-testid="current-load-meta">
                    <span class="font-medium text-base-content">{{ $board->department->name }}</span>
                    <span>{{ $board->modality->name }}</span>
                    <span>{{ $board->term->name }}</span>
                </p>
                @if (count($departments) > 1 || $modalityOptions->count() > 1)
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        @if (count($departments) > 1)
                            <div class="w-full md:w-80">
                                <x-select label="Departamento" wire:model.live="department" :options="$departments" option-value="code" option-label="name" class="select-sm max-md:h-11" />
                            </div>
                        @endif
                        @if ($modalityOptions->count() > 1)
                            <x-core::modality-scope :options="$modalityOptions" :selected="$board->modality->code" class="w-full md:w-56" />
                        @endif
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5 max-md:w-full" data-testid="load-card">
                <div class="grid gap-1">
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carga del departamento</span>
                    @if ($board->load === null)
                        <x-core::status-badge tone="draft" label="Sin carga" data-testid="load-status" />
                    @else
                        <x-core::status-badge :tone="$board->load->status->tone()" :label="$board->load->status->label()" data-testid="load-status" />
                    @endif
                </div>
                <div class="grid min-w-48 gap-1.5 max-md:flex-1" data-testid="load-progress">
                    <span class="flex items-baseline gap-1.5"><span class="font-mono text-xl font-semibold leading-none tabular-nums">{{ $withTeacher }}</span> <span class="text-sm text-neutral">de <span class="font-mono tabular-nums">{{ $total }}</span> con docente</span></span>
                    <progress class="progress progress-primary h-1.5 w-full" value="{{ $withTeacher }}" max="{{ max($total, 1) }}" aria-label="Grupos con docente: {{ $withTeacher }} de {{ $total }}"></progress>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="w-full md:w-80">
                <x-input wire:model.live.debounce.300ms="search" placeholder="Grupo, materia o docente" aria-label="Buscar grupo, materia o docente" icon="o-magnifying-glass" :clearable="$search !== ''" data-testid="current-load-search" />
            </div>
            @if (count($careers) > 1)
                <x-select wire:model.live="careerFilter" aria-label="Carrera" :options="collect($careers)->map(fn ($name, $code) => ['id' => $code, 'name' => $name])->values()->all()" placeholder="Carrera: todas" class="max-md:w-full" />
            @endif
            @if ($campusOptions->count() > 1)
                <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" :counts="$campusCounts" all />
            @endif
        </div>

        <div role="group" aria-label="Filtro rápido" class="flex flex-wrap gap-1.5" data-testid="current-load-chips">
            @foreach ($chips as $key => $label)
                <button type="button" wire:click="$set('state', '{{ $key }}')" aria-pressed="{{ $state === $key ? 'true' : 'false' }}" data-testid="chip-{{ $key }}"
                    @class(['btn btn-sm max-md:h-11', 'btn-primary' => $state === $key])>{{ $label }} <span class="font-mono tabular-nums">{{ $board->count($key) }}</span></button>
            @endforeach
        </div>

        @if ($visible === [])
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="current-load-none">
                No hay grupos con ese filtro.
                <button type="button" class="link" wire:click="$set('state', 'todos')">Ver todos</button>
            </div>
        @endif

        <div class="space-y-4" data-testid="current-load-list">
            @foreach ($visible as $subject)
                @php($canSpace = $spaceAllowed[$subject->subject->id] ?? false)
                @php($current = $subject->subject->required_space)
                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-subject="{{ $subject->subject->code }}" wire:key="subject-{{ $subject->subject->id }}">
                    <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-base-300 bg-base-200 px-4 py-2.5">
                        <h2 class="min-w-0 font-semibold">{{ $subject->subject->name }} <span class="font-mono text-sm font-normal text-neutral">{{ $subject->subject->code }}</span></h2>
                        <div class="flex flex-wrap items-center gap-2 text-sm text-neutral">
                            <span>Espacio de la materia ({{ count($subject->groups) }} {{ count($subject->groups) === 1 ? 'grupo' : 'grupos' }}):</span>
                            <div role="group" aria-label="Espacio de {{ $subject->subject->code }}" class="join" data-testid="space-{{ $subject->subject->code }}">
                                @foreach (\App\Modules\Academic\Enums\RequiredSpace::cases() as $space)
                                    <button type="button" wire:click="setSpace('{{ $subject->subject->id }}', '{{ $space->value }}')" @disabled(! $canSpace) aria-pressed="{{ $current === $space ? 'true' : 'false' }}"
                                        @class(['btn btn-sm join-item max-md:h-11', 'btn-primary' => $current === $space])>{{ $space->label() }}</button>
                                @endforeach
                            </div>
                            @if ($current === null)
                                <span class="rounded border border-dashed border-warning px-1.5 text-warning-content" data-testid="space-unmarked">Sin marcar</span>
                            @endif
                        </div>
                    </header>
                    <ul class="divide-y divide-base-300">
                        @foreach ($subject->groups as $row)
                            @php($group = $row->group)
                            <li class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto_minmax(0,12rem)] md:items-center md:gap-4" data-group="{{ $group->id }}" wire:key="group-{{ $group->id }}">
                                <div>
                                    <span class="font-semibold">{{ $group->code }}</span>
                                    <span class="font-mono text-xs text-neutral">Sem. {{ $group->semester }} · {{ $group->ownerCareer?->code }}@if ($group->campus) · {{ $group->campus->code }}@endif</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($row->chips as $chip)
                                        <span class="rounded bg-base-200 px-1.5 py-0.5 font-mono text-xs font-medium">{{ $chip['label'] }}@if ($chip['virtual']) · Virtual @elseif ($chip['room']) · {{ $chip['room'] }}@endif</span>
                                        @if ($chip['room'] === null && ! $chip['virtual'])
                                            <span class="rounded border border-dashed border-warning px-1.5 py-0.5 text-xs">Sin salón</span>
                                        @endif
                                    @endforeach
                                    @if ($row->unscheduled)
                                        <span class="rounded border border-dashed border-warning px-1.5 py-0.5 text-xs">Sin horario</span>
                                    @endif
                                    @foreach ($row->conflicts as $conflict)
                                        <span class="rounded border border-error bg-error/10 px-1.5 py-0.5 text-xs text-error" title="{{ $conflict['message'] }}">Choque · {{ $conflict['rule'] }}</span>
                                    @endforeach
                                </div>
                                <div class="flex items-center gap-2">
                                    @php($canManage = $manageAllowed[$group->id] ?? false)
                                    <label class="text-sm text-neutral" for="cupo-{{ $group->id }}">Cupo</label>
                                    <input id="cupo-{{ $group->id }}" type="number" min="1" inputmode="numeric" wire:model="capacities.{{ $group->id }}" wire:change="saveCapacity('{{ $group->id }}')" @disabled(! $canManage)
                                        class="input input-sm w-20 font-mono max-md:h-11 @error('capacities.'.$group->id) input-error @enderror" data-testid="capacity-{{ $group->code }}" @error('capacities.'.$group->id) aria-invalid="true" aria-describedby="cupo-error-{{ $group->id }}" @enderror>
                                </div>
                                <div class="min-w-0 text-sm">
                                    @if ($row->hasTeacher())
                                        <span class="font-medium">{{ $group->teacher?->name }}</span>
                                    @else
                                        <span class="text-neutral" data-testid="no-teacher">Sin docente</span>
                                    @endif
                                    <button type="button" class="link mt-0.5" wire:click="$dispatchTo('staffing::components.current-load-drawer', 'open-load-group', { id: '{{ $group->id }}' })" data-testid="open-group">Editar grupo</button>
                                </div>
                                @error('capacities.'.$group->id)
                                    <p id="cupo-error-{{ $group->id }}" class="text-sm text-error md:col-span-4" role="alert">{{ $message }}</p>
                                @enderror
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <livewire:staffing::components.current-load-drawer :term-id="$termId" :modality-id="$modalityId" :department-id="$departmentId" wire:key="drawer-{{ $termId }}-{{ $modalityId }}-{{ $departmentId }}" />
    @endif
</div>
