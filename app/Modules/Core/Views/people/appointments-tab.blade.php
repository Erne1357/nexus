{{--
    La pestaña «Nombramientos» de «Personas y puestos» (U3 opción A): filtros (persona, puesto, modalidad, campus,
    vigencia) y una tabla plana con un renglón por nombramiento: Persona, Puesto, Carrera o departamento, Modalidad,
    Campus («Todos los campus» si el nombramiento no fija uno) y Vigencia. Debajo de 768 px, renglones apilados. Espera
    $appointments, $rows, $titles, $roles, $modalities, $campuses, $currentOptions y $headers.
--}}
@php
    $stateTone = ['current' => ['success', 'Vigente'], 'ended' => ['neutral', 'Terminado'], 'future' => ['neutral', 'Aún no empieza']];
@endphp

<x-core::catalog-toolbar :narrow="true">
    <x-slot:search>
        <x-input name="appSearch" wire:model.live.debounce.300ms="appSearch" placeholder="Nombre o usuario" aria-label="Buscar persona" icon="o-magnifying-glass" clearable />
    </x-slot:search>

    <x-select wire:model.live="appRole" aria-label="Puesto" :options="$roles" placeholder="Puesto" />
    <x-select wire:model.live="appModality" aria-label="Modalidad" :options="$modalities" placeholder="Modalidad" />
    <x-select wire:model.live="appCampus" aria-label="Campus" :options="$campuses" placeholder="Campus" />
    <x-select wire:model.live="appCurrent" aria-label="Vigencia" :options="$currentOptions" />
</x-core::catalog-toolbar>

<div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="appointments-list">
    <x-table
        :headers="$headers"
        :rows="$appointments"
        class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
    >
        @scope('cell_person', $assignment)
            <a href="{{ route('core.people.show', $assignment->user_id) }}" wire:navigate class="font-semibold hover:underline">{{ $assignment->user->name }}</a>
            <div class="font-mono text-sm text-neutral">{{ $assignment->user->username }}</div>
        @endscope

        @scope('cell_role', $assignment, $titles)
            {{ $titles[$assignment->getKey()] }}
        @endscope

        @scope('cell_scope', $assignment, $rows)
            @php $row = $rows[$assignment->getKey()]; @endphp
            @if ($row['careerCode'] !== null)
                <span class="font-mono font-semibold">{{ $row['careerCode'] }}</span>
                @if ($row['careerName'] !== null)
                    <div class="text-sm text-neutral">{{ $row['careerName'] }}</div>
                @endif
            @else
                {{ $row['unit'] ?? '—' }}
            @endif
        @endscope

        @scope('cell_modality', $assignment, $rows)
            {{ $rows[$assignment->getKey()]['modality'] }}
        @endscope

        @scope('cell_campus', $assignment, $rows)
            {{ $rows[$assignment->getKey()]['campus'] }}
        @endscope

        @scope('cell_validity', $assignment, $rows, $stateTone)
            <div class="font-mono text-sm tabular-nums">{{ $assignment->valid_to === null ? 'Desde '.$assignment->valid_from->format('d/m/Y') : $assignment->valid_from->format('d/m/Y').' – '.$assignment->valid_to->format('d/m/Y') }}</div>
            <div class="text-sm text-neutral">{{ $assignment->valid_to === null && $rows[$assignment->getKey()]['state'] === 'current' ? 'Sin fecha de fin' : $stateTone[$rows[$assignment->getKey()]['state']][1] }}</div>
        @endscope

        <x-slot:empty>
            <x-icon name="o-users" label="Ningún nombramiento coincide con los filtros." />
        </x-slot:empty>
    </x-table>

    <ul class="divide-y divide-base-300 md:hidden" data-testid="appointment-cards">
        @foreach ($appointments as $assignment)
            @php $row = $rows[$assignment->getKey()]; @endphp
            <li wire:key="appointment-card-{{ $assignment->id }}" class="space-y-1 px-4 py-3">
                <a href="{{ route('core.people.show', $assignment->user_id) }}" wire:navigate class="font-semibold">{{ $assignment->user->name }}</a>
                <div>{{ $titles[$assignment->getKey()] }} · @if ($row['careerCode'] !== null)<span class="font-mono font-semibold">{{ $row['careerCode'] }}</span>@else{{ $row['unit'] ?? '—' }}@endif</div>
                <div class="text-sm text-neutral">{{ $row['modality'] }} · {{ $row['campus'] }}</div>
                <div class="font-mono text-sm tabular-nums">{{ $assignment->valid_to === null ? 'Desde '.$assignment->valid_from->format('d/m/Y') : $assignment->valid_from->format('d/m/Y').' – '.$assignment->valid_to->format('d/m/Y') }}</div>
            </li>
        @endforeach

        @if ($appointments->isEmpty())
            <li class="px-4 py-6 text-center text-neutral">Ningún nombramiento coincide con los filtros.</li>
        @endif
    </ul>

    @if ($appointments->isNotEmpty() || $appointments->hasPages())
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
            @if ($appointments->isNotEmpty())
                <p>Nombramientos <span class="font-mono">{{ $appointments->firstItem() }}–{{ $appointments->lastItem() }}</span> de <span class="font-mono">{{ $appointments->total() }}</span></p>
            @endif

            {{ $appointments->onEachSide(1)->links(data: ['scrollTo' => false]) }}
        </div>
    @endif
</div>
