{{--
    La lista de carreras de la bandeja (academic-load::pages.inbox): pendientes primero, cada una con su estado
    (la insignia; sin borde de color), sus totales para el departamento (con «Todos» y varios campus, los grupos de cada
    uno con su etiqueta; con el filtro, los de ese campus) y cuántas filas tienen anomalías. Elegir una
    la abre a la derecha.
--}}
<ul class="divide-y divide-base-300" data-testid="inbox-careers">
    @forelse ($visible as $row)
        @php($career = $row['career'])
        @php($isSelected = $selected !== null && $selected['career']->getKey() === $career->getKey())
        @php($anomalies = $anomalyCounts[$career->getKey()] ?? 0)
        <li wire:key="career-{{ $career->getKey() }}">
            <button type="button" wire:click="pick('{{ $career->getKey() }}')" aria-current="{{ $isSelected ? 'true' : 'false' }}" data-career="{{ $career->code }}"
                class="flex min-h-12 w-full min-w-0 cursor-pointer flex-col gap-1 px-4 py-3 text-left hover:bg-base-200 aria-[current=true]:bg-base-200">
                <span class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[15px] font-semibold">{{ $career->code }}</span>
                    <x-core::status-badge :tone="$row['status']->tone()" :label="$row['status']->label()" />
                </span>
                <span class="truncate text-sm" title="{{ $career->name }}">{{ $career->name }}</span>
                <span class="text-sm text-neutral">
                    @if ($row['review'] === null)
                        {{ $row['proposal'] === null || $row['proposal']->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Draft ? 'Sin propuesta' : 'Sin materias de este departamento' }}
                    @else
                        @php($figure = $figures[$career->getKey()])
                        @if (count($figure['byCampus']) > 1)
                            <span class="inline-flex flex-wrap items-center gap-x-1.5" data-testid="career-by-campus">
                                @foreach ($figure['byCampus'] as $code => $groups)
                                    <span class="inline-flex items-center gap-1"><x-core::campus-tag :campus="$campusOptions->firstWhere('code', $code) ?? (object) ['code' => $code, 'name' => $code]" /><span class="font-mono font-semibold text-base-content">{{ $groups }}</span></span>@if (! $loop->last)<span aria-hidden="true">·</span>@endif
                                @endforeach
                                <span>grupos · {{ $figure['subjects'] }} {{ $figure['subjects'] === 1 ? 'materia' : 'materias' }}</span>
                            </span>
                        @elseif ($figure['groups'] === 0 && $shownCampus !== null)
                            Nada en {{ $shownCampus->code }}
                        @else
                            {{ $figure['groups'] }} grupos · {{ $figure['subjects'] }} {{ $figure['subjects'] === 1 ? 'materia' : 'materias' }}
                        @endif
                    @endif
                </span>
                @if ($row['correctionBy'] !== [])
                    <span class="flex items-center gap-1.5 text-sm" data-testid="career-correction"><x-icon name="o-arrow-path" class="h-4 w-4 shrink-0 text-neutral" />En corrección por {{ implode(', ', $row['correctionBy']) }}</span>
                @endif
                @if ($anomalies > 0)
                    <span class="flex items-center gap-1.5 text-sm" data-testid="career-anomalies"><x-icon name="o-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning" />{{ $anomalies }} {{ $anomalies === 1 ? 'materia con anomalías' : 'materias con anomalías' }}</span>
                @endif
            </button>
        </li>
    @empty
        <li class="px-4 py-6 text-center text-sm text-neutral">Ninguna carrera pendiente.</li>
    @endforelse
</ul>
