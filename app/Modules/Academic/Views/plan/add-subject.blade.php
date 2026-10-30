{{--
    «Agregar materia» (academic::pages.plan, dentro de su x-modal): busca en el catálogo por clave o nombre
    (PlanGridEditorQuery::catalog(): cada palabra, desde dos letras) y lista las primeras coincidencias con su
    clave en Mono, nombre, departamento y horas. Las que ya están en la retícula, las inactivas y las borradas se
    ven, con el motivo en lugar del departamento, pero no se pueden elegir. Debajo, el semestre, la especialidad
    («Ninguna (tronco común)»; una inactiva no se elige) y si es obligatoria; «Agregar CLAVE» la pone. Los campos
    se llaman `add…` para no chocar con el formulario del panel; los errores de PlaceSubject caen en ellos.

    `$catalog` es el resultado de la búsqueda y `$addOptions`, las opciones de los selects.
--}}
<div class="space-y-4">
    <header class="pe-8">
        <h2 id="add-title" class="text-xl font-bold leading-tight">Agregar materia al semestre <span class="font-mono">{{ $addSemester }}</span></h2>
        <p class="mt-1 text-neutral">Busca entre las <span class="font-mono">{{ number_format($catalog['total']) }}</span> materias del catálogo.</p>
    </header>

    <div class="space-y-2">
        <x-input
            label="Materia"
            name="addSearch"
            wire:model.live.debounce.300ms="addSearch"
            placeholder="Clave o nombre, p. ej. SCD-10"
            icon="o-magnifying-glass"
            autocomplete="off"
            omit-error
        />

        @if (! $catalog['searched'])
            <p class="text-sm text-neutral">Escribe al menos dos letras de la clave o del nombre.</p>
        @elseif ($catalog['subjects'] === [])
            <p class="rounded-box border border-base-300 px-3 py-2.5 text-neutral">Ninguna materia coincide.</p>
        @else
            <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" aria-label="Materias del catálogo" data-testid="catalog">
                @foreach ($catalog['subjects'] as $subject)
                    @php
                        $reason = match (true) {
                            $subject['deleted'] => 'Borrada del catálogo',
                            $subject['semester'] !== null => 'Ya está en el semestre '.$subject['semester'],
                            ! $subject['is_active'] => 'Inactiva en el catálogo',
                            default => $subject['department'],
                        };
                        $isPicked = $addSubjectId === $subject['id'];
                    @endphp
                    <li wire:key="catalog-{{ $subject['id'] }}">
                        <button
                            type="button"
                            data-catalog="{{ $subject['code'] }}"
                            @if ($subject['placeable'])
                                wire:click="pick('{{ $subject['id'] }}')"
                                aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                            @else
                                disabled
                            @endif
                            @class([
                                'grid w-full grid-cols-[5.75rem_minmax(0,1fr)_auto] items-start gap-x-3 px-3 py-2 text-left',
                                'hover:bg-base-200 focus-visible:bg-base-200' => $subject['placeable'] && ! $isPicked,
                                'bg-primary/8 shadow-[inset_0_0_0_2px_var(--color-primary)]' => $isPicked,
                                'cursor-not-allowed text-neutral' => ! $subject['placeable'],
                            ])
                        >
                            <span @class(['font-mono text-[13.5px]', 'font-semibold' => $subject['placeable']])>{{ $subject['code'] }}</span>
                            <span class="min-w-0 leading-snug">
                                {{ $subject['name'] }}
                                <span class="block text-xs text-neutral">{{ $reason }}</span>
                            </span>
                            <span class="font-mono text-[13px]" title="Horas de teoría, de práctica y créditos">{{ $subject['theory_hours'] }}-{{ $subject['practice_hours'] }}-{{ $subject['credits'] }}</span>
                        </button>
                    </li>
                @endforeach
                <li class="bg-base-200/60 px-3 py-1.5 text-xs text-neutral" data-testid="catalog-count">
                    <span class="font-mono">{{ $catalog['matches'] }}</span> {{ $catalog['matches'] === 1 ? 'coincidencia' : 'coincidencias' }}@if ($catalog['matches'] > count($catalog['subjects'])), se muestran <span class="font-mono">{{ count($catalog['subjects']) }}</span>@endif
                </li>
            </ul>
        @endif

        @error('addSubjectId')
            <p class="text-sm text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-3">
        <x-select label="Semestre" name="addSemester" wire:model.live="addSemester" :options="$addOptions['semesters']" class="font-mono" data-testid="add-semester" />
        <x-select label="Especialidad" name="addSpecialtyId" wire:model="addSpecialtyId" :options="$addOptions['specialties']" placeholder="Ninguna (tronco común)" placeholder-value="" data-testid="add-specialty" />
    </div>
    <x-checkbox label="Obligatoria" name="addIsRequired" wire:model="addIsRequired" />

    <div class="modal-action mt-2">
        <x-button label="Cancelar" class="btn-ghost" @click="$wire.showAdd = false" />
        <x-button
            :label="$addSubjectId === null ? 'Agregar' : 'Agregar '.$addSubjectCode"
            icon="o-plus"
            class="btn-primary"
            wire:click="place"
            spinner="place"
            data-testid="add-submit"
        />
    </div>
</div>
