{{--
    Lo que dice la asignación sobre el estado de la carga: en propuesta, el aviso con «Pasar a asignación docente» para
    quien puede; si ya se sabe que Scheduling lo vetaría (choques duros, grupos sin horario), el botón deshabilitado y,
    debajo, los motivos en palabras con las ligas para arreglarlos; si lo veta al pasarla, sus motivos. En celular el
    texto y el botón se apilan. En borrador o después de ajustes, una línea de solo lectura; en asignación o ajustes,
    una línea para quien solo consulta.
--}}
@if ($board->load?->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Proposed)
    <div role="status" class="alert alert-info alert-soft grid-flow-row grid-cols-[auto_minmax(0,1fr)] items-start text-sm md:items-center" data-testid="load-banner">
        <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 md:mt-0" aria-hidden="true" />
        <div class="grid min-w-0 gap-3 md:flex md:items-center md:justify-between md:gap-6">
            <p id="load-banner-text"><span class="font-semibold">{{ $stateMessage }}</span> Al pasarla, toda la oferta del departamento queda a la vista de sus docentes.</p>
            @if ($canStart && $blockers === [])
                <x-button label="Pasar a asignación docente" icon="o-arrow-right-circle" class="btn-primary shrink-0 max-md:h-11 max-md:min-h-11 max-md:w-full" wire:click="startStaffing" spinner="startStaffing" data-testid="start-staffing" />
            @elseif ($canStart)
                <span class="shrink-0 max-md:w-full" title="Corrige primero el horario del departamento.">
                    <button type="button" disabled class="btn btn-primary max-md:h-11 max-md:min-h-11 max-md:w-full" aria-describedby="staffing-blockers-title" data-testid="start-staffing-disabled">
                        <x-icon name="o-arrow-right-circle" class="h-5 w-5" />Pasar a asignación docente
                    </button>
                </span>
            @endif
        </div>
    </div>

    @if ($canStart && $blockers !== [])
        <div class="alert alert-warning alert-soft grid-flow-row grid-cols-[auto_minmax(0,1fr)] items-start text-sm" data-testid="staffing-blockers">
            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
            <div class="grid min-w-0 gap-2">
                <p id="staffing-blockers-title" class="font-semibold">Para pasarla a Asignación, primero corrige el horario:</p>
                <ul class="list-disc space-y-0.5 ps-5">
                    @if (($blockers['conflicted'] ?? 0) > 0)
                        <li><span class="font-mono font-semibold tabular-nums">{{ $blockers['conflicted'] }}</span> {{ $blockers['conflicted'] === 1 ? 'grupo tiene' : 'grupos tienen' }} choques duros en su horario.</li>
                    @endif
                    @if (($blockers['unscheduled'] ?? 0) > 0)
                        <li><span class="font-mono font-semibold tabular-nums">{{ $blockers['unscheduled'] }}</span> {{ $blockers['unscheduled'] === 1 ? 'grupo no tiene' : 'grupos no tienen' }} horario.</li>
                    @endif
                </ul>
                <p class="flex flex-wrap gap-x-5 gap-y-1">
                    @if (\Illuminate\Support\Facades\Route::has('scheduling.conflicts'))
                        <a href="{{ route('scheduling.conflicts', ['campus' => $board->campus->code]) }}" wire:navigate class="link inline-flex min-h-11 items-center gap-1 font-semibold md:min-h-0" data-testid="blockers-conflicts-link">Ir a Choques y avance<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                    @endif
                    @if (\Illuminate\Support\Facades\Route::has('scheduling.editor'))
                        <a href="{{ route('scheduling.editor', ['campus' => $board->campus->code]) }}" wire:navigate class="link inline-flex min-h-11 items-center gap-1 font-semibold md:min-h-0">Abrir el editor de horarios<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                    @endif
                </p>
            </div>
        </div>
    @endif

    @if ($vetoReasons !== [])
        <div class="alert alert-error alert-soft grid-flow-row grid-cols-[auto_minmax(0,1fr)] items-start text-sm" data-testid="veto-reasons" role="alert">
            <x-icon name="o-x-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
            <div class="min-w-0">
                <p class="font-semibold">La carga sigue en Propuesta:</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    @foreach ($vetoReasons as $vetoReason)
                        <li>{{ $vetoReason }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@elseif ($stateMessage !== null)
    <p class="flex items-center gap-2 text-sm text-neutral" data-testid="read-only"><x-icon name="o-lock-closed" class="h-4 w-4 shrink-0" />{{ $stateMessage }}</p>
@elseif (! $canAssign)
    <p class="flex items-center gap-2 text-sm text-neutral" data-testid="read-only"><x-icon name="o-eye" class="h-4 w-4 shrink-0" />{{ $readOnlyReason }}</p>
@endif
