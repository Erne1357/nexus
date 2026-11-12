{{--
    «Nombrar» como cajón (T13, U3 opción A «Drawer con casillas»; ADR-0023): el puesto, la modalidad (solo se elige si hay
    más de una activa, MS16), el campus opcional (AZ-S17: «Todos los campus» por omisión) y, para el coordinador, las
    carreras por departamento con casillas y quién las coordina hoy (M5, MS19: un nombramiento por carrera, todos o
    ninguno); el jefe de proyecto docente pide su departamento. Los atajos «Todas las de …» marcan las carreras del
    campus o de toda la modalidad. Espera $person, $offered, $roleCase, $options, $isCoordinator y $careerGroups; el componente usa
    AppointsRoles.
--}}
@php
    $columns = [...($roleCase?->scopeColumns() ?? []), ...($roleCase?->optionalScopeColumns() ?? [])];
    $modalities = $options['modalities'];
    $modalityName = collect($modalities)->firstWhere('id', $modality)['name'] ?? 'la modalidad';
    $campusCode = collect($options['campuses'])->firstWhere('id', $campus);
    $campusName = $campusCode === null ? null : explode(' · ', $campusCode['name'], 2)[1] ?? $campusCode['name'];
    $selectable = collect($careerGroups)->flatMap(fn (array $group): array => $group['careers'])->where('state', '!=', 'mine');
    $count = count($careers) ?: ($career !== '' ? 1 : 0);
@endphp

<form wire:submit="appoint" class="flex h-dvh flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
        <div>
            <h2 id="appoint-title" class="text-xl font-bold">Nombrar a {{ $person->name }}</h2>
            <p class="text-sm text-neutral">El formulario pide solo el alcance que el puesto necesita.</p>
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" @click="$wire.showModal = false" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
        @error('user')
            <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
        @enderror

        <div>
            <x-select label="Puesto" name="role" wire:model.live="role" :options="$offered" placeholder="Elige un puesto" />
            <p class="mt-1 text-sm text-neutral">El puesto de docente se maneja en la ficha del docente.</p>
        </div>

        @if ($roleCase !== null)
            <p class="text-sm" data-testid="role-hint">{{ $roleCase->appointHint() ?? '' }}</p>
        @endif

        @if (in_array('modality_id', $columns, true))
            <div data-testid="field-modality">
                @if (count($modalities) > 1)
                    <fieldset data-testid="modality-picker">
                        <legend class="mb-1 text-sm font-semibold">Modalidad</legend>
                        <div class="join flex w-full flex-wrap">
                            @foreach ($modalities as $option)
                                <input type="radio" class="btn join-item flex-1 max-md:min-h-11" wire:model.live="modality" name="modality" value="{{ $option['id'] }}" aria-label="{{ $option['name'] }}" />
                            @endforeach
                        </div>
                    </fieldset>
                @else
                    <p class="text-sm" data-testid="modality-fixed"><span class="font-semibold">Modalidad</span> <span class="ms-2">{{ $modalities[0]['name'] ?? 'Sin modalidad activa' }}</span></p>
                @endif
                @error('modality')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if (in_array('campus_id', $columns, true))
            <fieldset data-testid="field-campus">
                <legend class="mb-1 text-sm font-semibold">Campus <span class="font-normal text-neutral">(opcional)</span></legend>
                <div class="join flex w-full flex-wrap">
                    <input type="radio" class="btn join-item flex-1 max-md:min-h-11" wire:model.live="campus" name="campus" value="" aria-label="Todos los campus" />
                    @foreach ($options['campuses'] as $option)
                        <input type="radio" class="btn join-item flex-1 max-md:min-h-11" wire:model.live="campus" name="campus" value="{{ $option['id'] }}" aria-label="{{ explode(' · ', $option['name'], 2)[1] ?? $option['name'] }}" />
                    @endforeach
                </div>
                <p class="mt-1 text-sm text-neutral">Sin campus trabaja todos los campus de la modalidad; con campus, solo ese.</p>
                @error('campus')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </fieldset>
        @endif

        @if ($isCoordinator)
            <section data-testid="career-checklist" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Carreras <span class="font-normal text-neutral">· {{ $count === 1 ? '1 elegida' : $count.' elegidas' }}</span></h3>
                    <div class="w-full sm:w-56">
                        <x-input name="careerSearch" wire:model.live.debounce.300ms="careerSearch" placeholder="Clave o nombre" aria-label="Buscar carrera" icon="o-magnifying-glass" clearable />
                    </div>
                </div>

                @error('career')
                    <p class="text-sm text-error" data-testid="career-error">{{ $message }}</p>
                @enderror

                <div class="overflow-hidden rounded-box border border-base-300">
                    <div class="flex flex-col divide-y divide-base-300 bg-base-200/60">
                        @if ($campusName !== null)
                            <button type="button" wire:click="selectAllInCampus" class="flex items-center gap-2 px-3 py-2.5 text-start text-sm font-semibold hover:bg-base-200 max-md:min-h-11" data-testid="select-all-campus">
                                <x-icon name="o-check-circle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                                Todas las de {{ $modalityName }} en {{ $campusName }}
                                <span class="font-normal text-neutral">({{ $selectable->count() }})</span>
                            </button>
                        @endif
                        <button type="button" wire:click="selectAllInModality" class="flex items-center gap-2 px-3 py-2.5 text-start text-sm font-semibold hover:bg-base-200 max-md:min-h-11" data-testid="select-all-modality">
                            <x-icon name="o-check-circle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                            Todas las de {{ $modalityName }}
                        </button>
                    </div>

                    @forelse ($careerGroups as $group)
                        @php
                            $ids = array_column(array_filter($group['careers'], fn (array $row): bool => $row['state'] !== 'mine'), 'id');
                            $picked = count(array_intersect($ids, $careers));
                        @endphp
                        <div wire:key="group-{{ $group['id'] }}" class="border-t border-base-300">
                            <label class="flex cursor-pointer items-center gap-3 bg-base-200/60 px-3 py-2 max-md:min-h-11">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:click="toggleDepartment('{{ $group['id'] }}')" @checked($ids !== [] && $picked === count($ids)) x-init="$el.indeterminate = {{ $picked > 0 && $picked < count($ids) ? 'true' : 'false' }}" aria-label="Todas las carreras de {{ $group['name'] }}" />
                                <span class="text-sm font-semibold"><span class="font-mono">{{ $group['code'] }}</span> · {{ $group['name'] }}</span>
                            </label>

                            @foreach ($group['careers'] as $row)
                                <label wire:key="career-{{ $row['id'] }}" @class(['flex items-start gap-3 border-t border-base-300 px-3 py-2.5 max-md:min-h-11', 'cursor-pointer' => $row['state'] !== 'mine', 'text-neutral' => $row['state'] === 'mine'])>
                                    <input type="checkbox" class="checkbox checkbox-sm mt-1" wire:model.live="careers" value="{{ $row['id'] }}" @disabled($row['state'] === 'mine') data-testid="career-{{ $row['code'] }}" />
                                    <span class="min-w-0">
                                        <span class="font-mono font-semibold">{{ $row['code'] }}</span>
                                        <span class="ms-2">{{ $row['name'] }}</span>
                                        <span class="mt-0.5 flex items-center gap-1 text-sm text-neutral">
                                            <x-icon :name="$row['state'] === 'free' ? 'o-exclamation-triangle' : 'o-user'" @class(['h-4 w-4 shrink-0', 'text-warning' => $row['state'] === 'free']) aria-hidden="true" />
                                            {{ $row['state'] === 'mine' ? 'Ya la coordina esta persona' : $row['holders'] }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @empty
                        <p class="border-t border-base-300 px-3 py-4 text-sm text-neutral">No hay carreras que se ofrezcan {{ $campusName === null ? 'en un campus activo' : 'en '.$campusName }}.</p>
                    @endforelse
                </div>
            </section>
        @elseif (in_array('career_id', $columns, true))
            <div data-testid="field-career">
                <x-select label="Carrera" name="career" wire:model="career" :options="$options['careers']" placeholder="Elige la carrera" />
            </div>
        @endif

        @if (in_array('org_unit_id', $columns, true))
            <div data-testid="field-unit">
                <x-select :label="$roleCase->unitLabel() ?? 'Departamento académico'" name="unit" wire:model="unit" :options="$options['units']" placeholder="Elige" />
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-input label="Desde" name="from" type="date" wire:model="from" />
            <x-input label="Hasta (opcional)" name="to" type="date" wire:model="to" />
        </div>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3">
        <p class="text-sm text-neutral">Cada nombramiento queda en la bitácora.</p>
        <div class="flex gap-2">
            <x-button label="Cancelar" @click="$wire.showModal = false" class="max-md:h-11" />
            <x-button :label="$isCoordinator && $count > 1 ? 'Nombrar en '.$count.' carreras' : 'Nombrar'" type="submit" icon="o-plus" class="btn-primary max-md:h-11" spinner="appoint" data-testid="appoint-submit" />
        </div>
    </footer>
</form>
