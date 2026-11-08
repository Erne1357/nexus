{{--
    Lo que dice la asignación sobre el estado de la carga: en propuesta, el aviso con «Pasar a asignación docente» para
    quien puede (y los motivos si se veta); en borrador o después de ajustes, una línea de solo lectura; en asignación o
    ajustes, una línea para quien solo consulta.
--}}
@if ($board->load?->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Proposed)
    <x-alert icon="o-information-circle" class="alert-info alert-soft items-center text-sm" data-testid="load-banner">
        <span>
            <span class="font-semibold">{{ $stateMessage }}</span>
            Al pasarla, toda la oferta del departamento queda a la vista de sus docentes.
        </span>
        @if ($canStart)
            <x-slot:actions>
                <x-button label="Pasar a asignación docente" icon="o-arrow-right-circle" class="btn-primary max-md:h-11 max-md:min-h-11" wire:click="startStaffing" spinner="startStaffing" data-testid="start-staffing" />
            </x-slot:actions>
        @endif
    </x-alert>

    @if ($vetoReasons !== [])
        <x-alert icon="o-x-circle" class="alert-error alert-soft text-sm" data-testid="veto-reasons" role="alert">
            <div>
                <p class="font-semibold">La carga sigue en Propuesta:</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    @foreach ($vetoReasons as $vetoReason)
                        <li>{{ $vetoReason }}</li>
                    @endforeach
                </ul>
            </div>
        </x-alert>
    @endif
@elseif ($stateMessage !== null)
    <p class="flex items-center gap-2 text-sm text-neutral" data-testid="read-only"><x-icon name="o-lock-closed" class="h-4 w-4 shrink-0" />{{ $stateMessage }}</p>
@elseif (! $canAssign)
    <p class="flex items-center gap-2 text-sm text-neutral" data-testid="read-only"><x-icon name="o-eye" class="h-4 w-4 shrink-0" />Solo consulta: asignan el jefe del departamento y el jefe de proyecto docente.</p>
@endif
