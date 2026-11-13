{{--
    Selectores del editor de horarios: Modalidad (solo con más de una, MS16; maqueta U2, opción A); Carrera; Retícula, un segmentado con la vigente («Vigente») y cada vieja
    («Prioridad» y cuántos grupos tiene sin acomodar; «Inactiva» si el plan ya no está activo pero tiene grupos), o su
    clave como texto si la carrera tiene una sola (un segmentado de una opción no elige nada); Semestre («Semestre 3 · 4
    sin acomodar»); el docente o el salón en esas vistas; Campus (un campus a la vez, con los grupos del semestre en cada
    uno; solo con más de uno), y «Ver por». El semestre, el docente o el salón y «Ver por» se
    parten juntos, así «Ver por» nunca queda solo en un renglón. Debajo de 768 px cada control ocupa el ancho.
--}}
@php
    $careerOptions = array_map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['code'].' · '.$row['name']], $careers);

    if ($career && ! in_array($career->id, array_column($careers, 'id'), true)) {
        $careerOptions[] = ['id' => $career->id, 'name' => $career->code.' · '.$career->name];
    }

    $segment = 'btn join-item h-10 gap-2 border-base-300 font-medium whitespace-nowrap shadow-none aria-pressed:btn-primary not-aria-pressed:bg-base-100 not-aria-pressed:hover:bg-base-200 max-md:flex-1 max-md:px-2';
@endphp
<div class="flex flex-wrap items-end gap-x-4 gap-y-3" data-testid="editor-controls">
    <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" class="max-md:w-full md:w-56" />

    <div class="max-md:w-full md:min-w-72">
        <x-select label="Carrera" wire:model.live="careerId" :options="$careerOptions" data-testid="editor-career" />
    </div>

    @isset($plan)
        <fieldset class="fieldset py-0 max-md:w-full">
            <legend class="fieldset-legend mb-0.5">Retícula</legend>
            @if (count($plans) === 1)
                <div class="flex h-10 items-center gap-2 text-sm font-medium whitespace-nowrap" data-testid="plan-{{ $plans[0]['code'] }}">
                    <span class="font-mono">{{ $plans[0]['code'] }}</span>
                    @if (! ($plans[0]['is_active'] ?? true))
                        <x-core::status-badge tone="neutral" label="Inactiva" />
                    @endif
                    @if ($plans[0]['is_current'])
                        <x-core::status-badge tone="success" label="Vigente" />
                    @else
                        <x-core::status-badge tone="warning" icon="o-exclamation-triangle" label="Prioridad" />
                        @if ($plans[0]['unscheduled'] > 0)
                            <span class="badge badge-warning badge-sm font-mono">{{ $plans[0]['unscheduled'] }}<span class="sr-only"> {{ $plans[0]['unscheduled'] === 1 ? 'grupo' : 'grupos' }} sin acomodar</span></span>
                        @endif
                    @endif
                </div>
            @else
            <div class="join max-md:join-vertical max-md:w-full" role="group" aria-label="Retícula">
                @foreach ($plans as $row)
                    <button type="button" wire:click="choosePlan('{{ $row['id'] }}')" aria-pressed="{{ $row['id'] === $plan->id ? 'true' : 'false' }}" @class([$segment, 'max-md:justify-start']) data-testid="plan-{{ $row['code'] }}">
                        <span class="font-mono">{{ $row['code'] }}</span>
                        @if (! ($row['is_active'] ?? true))
                            <x-core::status-badge tone="neutral" label="Inactiva" />
                        @endif
                        @if ($row['is_current'])
                            <x-core::status-badge tone="success" label="Vigente" />
                        @else
                            <x-core::status-badge tone="warning" icon="o-exclamation-triangle" label="Prioridad" />
                            @if ($row['unscheduled'] > 0)
                                <span class="badge badge-warning badge-sm font-mono">{{ $row['unscheduled'] }}<span class="sr-only"> {{ $row['unscheduled'] === 1 ? 'grupo' : 'grupos' }} sin acomodar</span></span>
                            @endif
                        @endif
                    </button>
                @endforeach
            </div>
            @endif
        </fieldset>

        <div class="flex grow flex-wrap items-end gap-x-4 gap-y-3 max-md:w-full">
        <div class="max-md:w-full md:min-w-56">
            <x-select label="Semestre" wire:model.live="semester" data-testid="editor-semester"
                :options="array_map(fn (array $row): array => ['id' => (string) $row['semester'], 'name' => 'Semestre '.$row['semester'].($row['unscheduled'] > 0 ? ' · '.$row['unscheduled'].' sin acomodar' : '')], $semesters)" />
        </div>

        @if ($view === 'docente' && $teacherOptions !== [])
            <div class="max-md:w-full md:min-w-64">
                <x-select label="Docente" wire:model.live="teacherId" data-testid="editor-teacher"
                    :options="array_map(fn (string $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($teacherOptions), $teacherOptions)" />
            </div>
        @elseif ($view === 'salon' && $roomOptions !== [])
            <div class="max-md:w-full md:min-w-44">
                <x-select label="Salón" wire:model.live="roomId" class="font-mono" data-testid="editor-room"
                    :options="array_map(fn (string $id, string $code): array => ['id' => $id, 'name' => $code], array_keys($roomOptions), $roomOptions)" />
            </div>
        @endif

        @if ($campusOptions->count() > 1)
            <fieldset class="fieldset min-w-0 py-0 max-md:w-full" data-testid="editor-campus">
                <legend class="fieldset-legend mb-0.5">Campus</legend>
                <x-core::campus-filter :options="$campusOptions" :selected="$campusCode" :counts="$campusCounts" />
            </fieldset>
        @endif

        <fieldset class="fieldset py-0 max-md:w-full md:ms-auto">
            <legend class="fieldset-legend mb-0.5">Ver por</legend>
            <div class="join max-md:flex max-md:w-full" role="group" aria-label="Ver por">
                @foreach (['semestre' => 'Semestre', 'docente' => 'Docente', 'salon' => 'Salón'] as $value => $label)
                    <button type="button" wire:click="setView('{{ $value }}')" aria-pressed="{{ $view === $value ? 'true' : 'false' }}" @class([$segment]) data-testid="view-{{ $value }}">{{ $label }}</button>
                @endforeach
            </div>
        </fieldset>
        </div>
    @endisset
</div>
