{{--
    La confirmación de quitar de la retícula la materia del panel (academic::pages.plan, dentro de su x-modal):
    su clave, nombre, semestre y especialidad; la seriación que se va con ella, como materia (lo que pide) y como
    requisito (quién la pide), con su tipo; qué materias se quedan sin ningún requisito; y que cambia el avance de
    los alumnos del plan. Academic no sabe cuántos alumnos tienen el plan (es de Students), así que no da la cifra.

    `$row` es la fila de PlanGridEditorQuery, `$removal['orphans']` las claves que se quedan sin seriación y
    `$specialtiesById` las especialidades del plan.
--}}
@php
    $seriations = count($row['requires']) + count($row['required_by']);
    $kind = fn (array $requisite): string => $requisite['kind'] === 'seriacion' ? 'Seriación' : 'Correquisito';
@endphp
<div class="space-y-4">
    <header class="pe-8">
        <h2 id="remove-title" class="text-xl font-bold leading-tight">Quitar <span class="font-mono">{{ $row['code'] }}</span> de la retícula</h2>
        <p class="mt-1 text-neutral">
            {{ $row['name'] }} · semestre <span class="font-mono">{{ $row['semester'] }}</span>@if ($row['specialty_id'] !== null) · especialidad {{ $specialtiesById[$row['specialty_id']]['name'] ?? 'quitada' }}@endif
        </p>
    </header>

    @if ($seriations === 0)
        <p>No tiene seriación: no pide ninguna materia y ninguna la pide.</p>
    @else
        <p>{!! $seriations === 1 ? 'Se va con ella <span class="font-semibold">1 seriación</span>:' : 'Se van con ella sus <span class="font-mono font-semibold">'.$seriations.'</span> seriaciones:' !!}</p>
        <ul class="divide-y divide-base-300 rounded-box border border-base-300" data-testid="seriation-gone">
            @foreach ($row['requires'] as $requisite)
                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 px-3 py-2 sm:grid-cols-[8.5rem_minmax(0,1fr)_auto]" wire:key="gone-requires-{{ $requisite['prerequisite_id'] }}">
                    <span class="text-[11.5px] uppercase tracking-[0.09em] text-neutral max-sm:col-span-2">Como materia</span>
                    <span class="min-w-0 leading-snug">
                        pide <span class="font-mono font-semibold">{{ $requisite['code'] }}</span>
                        <span class="block text-sm text-neutral">{{ $requisite['name'] }}</span>
                    </span>
                    <span class="text-sm text-neutral">{{ $kind($requisite) }}</span>
                </li>
            @endforeach
            @foreach ($row['required_by'] as $requisite)
                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 px-3 py-2 sm:grid-cols-[8.5rem_minmax(0,1fr)_auto]" wire:key="gone-required-by-{{ $requisite['prerequisite_id'] }}">
                    <span class="text-[11.5px] uppercase tracking-[0.09em] text-neutral max-sm:col-span-2">Como requisito</span>
                    <span class="min-w-0 leading-snug">
                        <span class="font-mono font-semibold">{{ $requisite['code'] }}</span> la pide
                        <span class="block text-sm text-neutral">{{ $requisite['name'] }}</span>
                    </span>
                    <span class="text-sm text-neutral">{{ $kind($requisite) }}</span>
                </li>
            @endforeach
        </ul>

        @if ($removal['orphans'] !== [])
            <p class="text-sm text-neutral">
                @foreach ($removal['orphans'] as $code)<span class="font-mono font-semibold text-base-content">{{ $code }}</span>@if (! $loop->last){{ $loop->remaining === 1 ? ' y ' : ', ' }}@endif @endforeach
                {{ count($removal['orphans']) === 1 ? 'se queda' : 'se quedan' }} sin seriación.
            </p>
        @endif
    @endif

    <x-alert icon="o-information-circle" class="alert-info alert-soft text-sm" title="Cambia el avance de los alumnos con este plan. La materia sigue en el catálogo." />

    <div class="modal-action mt-2">
        <x-button label="Cancelar" class="btn-ghost" @click="$wire.showRemove = false" />
        <x-button label="Quitar de la retícula" icon="o-trash" class="btn-error" wire:click="removePlacement('{{ $row['placement_id'] }}')" spinner="removePlacement('{{ $row['placement_id'] }}')" data-testid="confirm-remove" />
    </div>
</div>
