{{--
    Retícula de un plan (DESIGN.md, "Retícula del plan (editor)"; variante A aprobada el 29 de octubre de 2026):
    el encabezado del plan con la entrada a sus especialidades; la leyenda; y sus materias en la misma cuadrícula
    que la retícula del alumno, con las piezas de Core (core::semester-grid): mosaicos de tamaño fijo, el tronco
    común arriba y una banda por especialidad; en celular los semestres se apilan. En el encabezado de cada
    semestre (hasta el último que se ofrece), «Agregar materia» abre la búsqueda en el catálogo; un plan sin
    semestres ni materias la ofrece en su estado vacío. Debajo, las cadenas de seriación y la barra de selección.
    Tocar una materia la abre en el panel lateral (x-drawer a la derecha; a pantalla completa en celular), donde
    se edita su lugar o se quita, y resalta su cadena; el panel también gestiona las especialidades. «Agregar
    materia» y la confirmación de quitar son x-modal de la página, fuera del panel. El resaltado lo pinta el
    servidor con data-focus, data-me y data-on-chain, que leen las utilidades de Tailwind: la retícula cambia con
    cada edición y así no hay estado que se desfase.
--}}
<div class="space-y-5">
    <a href="{{ route('academic.plans') }}" wire:navigate data-testid="back-to-plans" class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="w-4 h-4" />Planes de estudio
    </a>

    <div>
        <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.625rem] font-bold leading-tight md:text-3xl">
            {{ $plan->name }}
            <span class="font-mono text-xl font-semibold text-neutral tabular-nums">{{ $plan->code }}</span>
            <x-core::status-badge :tone="$plan->is_active ? 'success' : 'neutral'" :label="$plan->is_active ? 'Activo' : 'Inactivo'" />
        </h1>
        <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral" data-testid="plan-facts">
            <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $plan->career?->code }}</span> · {{ $plan->career?->name }}</span></span>
            <span>Versión <span class="font-mono font-medium text-base-content">{{ $plan->version }}</span></span>
            <span>Semestres <span class="font-mono font-medium text-base-content">{{ $plan->semesters_count ?? '—' }}</span></span>
            <span>Créditos <span class="font-mono font-medium text-base-content">{{ $plan->total_credits ?? '—' }}</span></span>
            <span>Materias <span class="font-mono font-medium text-base-content">{{ $subjectsCount }}</span></span>
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5" data-testid="specialty-chips">
            <span class="mr-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Especialidades</span>
            @forelse ($grid['specialties'] as $specialty)
                <span @class(['inline-flex h-7 items-center gap-1.5 rounded-field border border-base-300 bg-base-100 px-2 text-[12.5px]', 'text-neutral' => ! $specialty['is_active']]) wire:key="chip-{{ $specialty['id'] }}">
                    {{ $specialty['name'] }}
                    <span class="font-mono text-neutral">{{ $specialty['subjects'] }}</span>
                    @unless ($specialty['is_active'])
                        <span>· inactiva</span>
                    @endunless
                </span>
            @empty
                <span class="text-neutral">Este plan no tiene especialidades.</span>
            @endforelse
        </div>

        <x-button label="Gestionar especialidades" icon="o-tag" class="btn-outline btn-sm border-base-300 max-md:w-full" wire:click="openSpecialties" spinner="openSpecialties" data-testid="open-specialties" />
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-neutral" data-testid="plan-legend">
        <span class="inline-flex items-center gap-1"><span class="w-6 h-6 shrink-0 rounded border border-base-300 bg-base-100" aria-hidden="true"></span>Obligatoria</span>
        <span class="inline-flex items-center gap-1"><span class="w-6 h-6 shrink-0 rounded border border-dashed border-neutral bg-base-100" aria-hidden="true"></span>Optativa</span>
        <span class="inline-flex items-center gap-1"><span class="inline-flex w-6 h-6 shrink-0 items-center justify-center rounded border border-base-300 bg-base-200" aria-hidden="true"><x-icon name="o-minus-circle" class="w-4 h-4" /></span>Inactiva en el catálogo</span>
        <span><span class="font-mono font-semibold text-base-content">3-2-5</span> horas de teoría, de práctica y créditos</span>
        <span><span class="font-semibold text-base-content">Pide</span> seriación · <span class="font-semibold text-base-content">Correq.</span> correquisito</span>
    </div>

    <div class="space-y-4" x-on:keydown.escape.window="if ($wire.chainFocus !== null && ! $wire.showDrawer && document.body.dataset.menu !== 'open') $wire.clearFocus()">
        @if ($grid['semesters'] === [])
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center" data-testid="empty-grid">
                <p class="text-neutral">Este plan todavía no tiene semestres ni materias en su retícula.</p>
                <x-button label="Agregar materia" icon="o-plus" class="btn-primary btn-sm mt-3" wire:click="openAdd(1)" spinner="openAdd(1)" data-testid="add-first-subject" />
            </div>
        @else
            <x-core::semester-grid.frame :layout="$layout" data-testid="plan-grid" :data-focus="$focused">
                @foreach ($layout->bands as $specialtyId => $bandRow)
                    <x-core::semester-grid.band :specialty="$specialtyId" :row="$bandRow" :name="$specialtiesById[$specialtyId]['name'] ?? 'Quitada'" :subjects="$bandSubjects[$specialtyId]" wire:key="band-{{ $specialtyId }}" />
                @endforeach

                @foreach ($grid['semesters'] as $semester => $rows)
                    <x-core::semester-grid.semester :semester="$semester" :column="$layout->columns[$semester]" wire:key="semester-{{ $semester }}">
                        <x-slot:figures>{{ $commonCredits[$semester] }} cr</x-slot:figures>
                        @if ($semester <= $offeredSemesters)
                            {{-- El tooltip va a la izquierda: arriba, el del último semestre se saldría de la caja y la desplazaría. --}}
                            <x-slot:actions>
                                <x-button
                                    icon="o-plus"
                                    class="btn-ghost btn-xs btn-square -my-1 -mr-1 shrink-0 text-neutral hover:text-base-content"
                                    tooltip-left="Agregar materia"
                                    wire:click="openAdd({{ $semester }})"
                                    spinner="openAdd({{ $semester }})"
                                    aria-label="Agregar materia al semestre {{ $semester }}"
                                    data-add-semester="{{ $semester }}"
                                />
                            </x-slot:actions>
                        @endif

                        @foreach ($rows as $row)
                            @if ($row['specialty_id'] !== null && ($loop->first || $rows[$loop->index - 1]['specialty_id'] !== $row['specialty_id']))
                                <x-core::semester-grid.separator :specialty="$row['specialty_id']" :name="$specialtiesById[$row['specialty_id']]['name'] ?? 'Quitada'" wire:key="separator-{{ $semester }}-{{ $row['specialty_id'] }}" />
                            @endif

                            @include('academic::plan.tile', ['row' => $row, 'gridRow' => $layout->rows[$row['placement_id']], 'isSelected' => $selected !== null && $selected['placement_id'] === $row['placement_id']])
                        @endforeach
                    </x-core::semester-grid.semester>
                @endforeach
            </x-core::semester-grid.frame>
        @endif

        @if ($grid['chains'] !== [])
            <div x-data="{ open: false }" data-testid="chain-legend">
                {{-- En celular (menos de 768 px) la leyenda va plegada tras este botón; desde md siempre está abierta. --}}
                <button type="button" data-testid="chain-legend-toggle" class="btn btn-sm btn-ghost md:hidden" aria-controls="plan-chain-chips" x-bind:aria-expanded="open.toString()" aria-expanded="false" @click="open = ! open">
                    Cadenas de seriación ({{ count($grid['chains']) }})
                </button>
                <div id="plan-chain-chips" class="flex flex-wrap items-center gap-1.5" x-bind:class="open ? '' : 'max-md:hidden'">
                    <span class="mr-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:hidden">Cadenas de seriación</span>
                    @foreach ($grid['chains'] as $chain)
                        <button type="button" data-chain="{{ $chain['index'] }}" aria-pressed="{{ $chainFocus === $chain['index'] ? 'true' : 'false' }}" wire:click="focusChain({{ $chain['index'] }})"
                            style="--chain: var(--color-chain-{{ $chain['index'] % 12 + 1 }})" wire:key="chain-{{ $chain['index'] }}"
                            class="inline-flex h-7 items-center gap-1.5 rounded-field border border-base-300 bg-base-100 px-2 text-[12.5px] aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary">
                            <span class="size-3.5 rounded-sm border-[3px] border-(--chain)" aria-hidden="true"></span>
                            {{ $chain['name'] }}
                            <span class="font-mono text-neutral">{{ $chain['size'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <x-core::semester-grid.selection-bar :shown="$info !== ''">
            <x-slot:info>{{ $info }}</x-slot:info>
            <x-slot:clear wire:click="clearFocus"></x-slot:clear>
        </x-core::semester-grid.selection-bar>
    </div>

    {{--
        «Agregar materia» y la confirmación de quitar van en la página y no dentro del panel: el panel se desliza
        con transform, y un modal dentro quedaría encerrado en él. Esc los cierra sin llegar al panel, que lo
        escucha en window (si no, cerraría también el panel y quitaría la selección).
    --}}
    <x-modal wire:model="showAdd" box-class="max-w-2xl" aria-labelledby="add-title" data-testid="add-modal" x-on:keydown.escape.stop="$wire.showAdd = false">
        @if ($showAdd && $catalog !== null)
            @include('academic::plan.add-subject')
        @endif
    </x-modal>

    <x-modal wire:model="showRemove" box-class="max-w-xl" aria-labelledby="remove-title" data-testid="remove-modal" x-on:keydown.escape.stop="$wire.showRemove = false">
        @if ($removal !== null)
            @include('academic::plan.remove-placement', ['row' => $selected])
        @endif
    </x-modal>

    {{-- Los atributos que no son de x-drawer caen en su panel (la x-card): ahí van la semántica de diálogo y el título que lo nombra. MaryUI pone la trampa de foco, Esc e inert. --}}
    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="plan-drawer-title" data-testid="plan-drawer">
        @if ($panel === 'specialties')
            @include('academic::plan.specialties-panel')
        @elseif ($selected !== null)
            @include('academic::plan.subject-panel', ['row' => $selected])
        @endif
    </x-drawer>
</div>
