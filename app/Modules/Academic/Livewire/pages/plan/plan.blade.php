{{--
    Retícula de un plan (DESIGN.md, "Retícula del plan (editor)"; variante A aprobada el 29 de octubre de 2026):
    el encabezado del plan con la entrada a sus especialidades; la leyenda; las cadenas de seriación; y sus
    materias en columnas por semestre, como la retícula del alumno, con el tronco común antes que cada
    especialidad. En celular los semestres se apilan. Tocar una materia la abre en el panel lateral (x-drawer a
    la derecha; a pantalla completa en celular) y resalta su cadena; el panel también gestiona las
    especialidades. El resaltado lo pinta el servidor con data-focus, data-me y data-on-chain, que leen las
    utilidades de Tailwind: la retícula cambia con cada edición y así no hay estado que se desfase.
--}}
<div class="space-y-5">
    <a href="{{ route('academic.plans') }}" wire:navigate data-testid="back-to-plans" class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="size-4" />Planes de estudio
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

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-neutral">
        <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded border border-base-300 bg-base-100" aria-hidden="true"></span>Obligatoria</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded border border-dashed border-neutral bg-base-100" aria-hidden="true"></span>Optativa</span>
        <span class="inline-flex items-center gap-1.5"><x-core::status-badge tone="neutral" label="Inactiva" class="h-5.5" /> en el catálogo</span>
        <span><span class="font-mono font-semibold text-base-content">3-2-5</span> horas de teoría, de práctica y créditos</span>
        <span><span class="font-semibold text-base-content">Pide</span> seriación · <span class="font-semibold text-base-content">Correq.</span> correquisito</span>
    </div>

    <div class="space-y-3" x-on:keydown.escape.window="if ($wire.chainFocus !== null && ! $wire.showDrawer && document.body.dataset.menu !== 'open') $wire.clearFocus()">
        @if ($grid['chains'] !== [])
            <div class="flex flex-wrap items-center gap-1.5" data-testid="chain-legend">
                <span class="mr-1 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Cadenas de seriación</span>
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
        @endif
        <p class="min-h-6 text-neutral" aria-live="polite" data-testid="chain-info">{{ $info }}</p>

        @if ($grid['semesters'] === [])
            <p class="rounded-box border border-base-300 bg-base-100 px-5 py-6 text-center text-neutral">Este plan todavía no tiene semestres ni materias en su retícula.</p>
        @else
            <div class="relative overflow-x-auto rounded-box border border-base-300 bg-base-100 max-md:overflow-visible max-md:border-0 max-md:bg-transparent">
                <div class="group/grid flex min-w-max max-md:min-w-0 max-md:flex-col max-md:gap-3 md:min-w-full" data-testid="plan-grid" @if ($focused) data-focus @endif>
                    @foreach ($grid['semesters'] as $semester => $rows)
                        <section class="flex min-w-28 flex-1 basis-0 flex-col gap-2 border-base-300 px-1.5 pt-2.5 pb-3 md:[&:not(:first-child)]:border-l max-md:grid max-md:grid-cols-2 max-md:rounded-box max-md:border max-md:bg-base-100 max-md:px-2"
                            aria-label="Semestre {{ $semester }}" data-semester="{{ $semester }}" wire:key="semester-{{ $semester }}">
                            <h3 class="px-0.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:col-span-2">
                                Semestre {{ $semester }}
                                <span class="flex flex-wrap gap-x-2 text-xs normal-case tracking-normal">
                                    @if ($summaries[$semester]['subjects'] === 0)
                                        Sin materias
                                    @else
                                        <span class="whitespace-nowrap">{{ trans_choice('{1} 1 materia|[2,*] :count materias', $summaries[$semester]['subjects']) }}</span>
                                        <span class="whitespace-nowrap"><span class="font-mono">{{ $summaries[$semester]['credits'] }}</span> cr{{ $summaries[$semester]['withSpecialties'] ? ' + esp.' : '' }}</span>
                                    @endif
                                </span>
                            </h3>

                            @foreach ($rows as $row)
                                @if ($row['specialty_id'] !== null && ($loop->first || $rows[$loop->index - 1]['specialty_id'] !== $row['specialty_id']))
                                    <p class="mt-1 border-t border-dashed border-base-300 px-0.5 pt-2 text-xs leading-snug max-md:col-span-2" wire:key="specialty-{{ $semester }}-{{ $row['specialty_id'] }}">
                                        <span class="block text-[11.5px] uppercase tracking-[0.09em] text-neutral">Especialidad</span>
                                        <span class="font-semibold">{{ $specialtiesById[$row['specialty_id']]['name'] ?? 'Quitada' }}</span>
                                    </p>
                                @endif

                                @include('academic::plan.tile', ['row' => $row, 'isSelected' => $selected !== null && $selected['placement_id'] === $row['placement_id']])
                            @endforeach

                            {{-- Tarea 1.8.9: «Agregar materia» al pie de cada semestre (un botón punteado que abre la búsqueda en el catálogo). --}}
                        </section>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Los atributos que no son de x-drawer caen en su panel (la x-card): ahí van la semántica de diálogo y el título que lo nombra. MaryUI pone la trampa de foco, Esc e inert. --}}
    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="plan-drawer-title" data-testid="plan-drawer">
        @if ($panel === 'specialties')
            @include('academic::plan.specialties-panel')
        @elseif ($selected !== null)
            @include('academic::plan.subject-panel', ['row' => $selected])
        @endif
    </x-drawer>
</div>
