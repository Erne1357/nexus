{{--
    El renglón de selectores (variante C): la corrida, y con una lista, el campus (con las sesiones que cambian en cada
    uno), «Ver por» con su lente y, a la derecha, Descartar y Aceptar (o el motivo de que no se pueda). Debajo, la línea de
    la corrida: motor, cuándo y quién, cuánto tardó, su estado, sus violaciones duras, su puntaje (los dos abren el
    drawer del puntaje) y cuántas sesiones cambian.
--}}
@php
    $ready = $run->status === \App\Modules\Solver\Enums\SolverRunStatus::Done && $comparison !== null;
    $hard = $output ? count($output->hardViolations) : 0;
    $keptCount = $ready ? count($comparison->plan->kept()) : 0;
    $viewLabel = \App\Modules\Solver\Queries\RunComparison::VIEWS[$view];
@endphp
<div class="flex flex-wrap items-end gap-x-3.5 gap-y-2.5" data-testid="runs-controls">
    <fieldset class="fieldset min-w-0 py-0 max-md:w-full">
        <legend class="fieldset-legend mb-0.5 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Corrida</legend>
        <label class="select w-full max-md:h-11 md:w-88">
            <select aria-label="Corrida" wire:change="selectRun($event.target.value)" data-testid="run-select">
                @foreach ($runs as $each)
                    <option value="{{ $each['run']->id }}" @selected($each['run']->id === $run->id)>Corrida {{ $each['number'] }} · {{ $scopeText($each['run']) }} · {{ $each['run']->status->label() }}</option>
                @endforeach
            </select>
        </label>
    </fieldset>

    @if ($ready)
        <fieldset class="fieldset min-w-0 py-0 max-md:w-full">
            <legend class="fieldset-legend mb-0.5 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Campus</legend>
            <x-core::campus-filter :options="$campusOptions" :selected="$campus" all :counts="$campusChanges" :total="$allStats['changes']" />
        </fieldset>
        <fieldset class="fieldset min-w-0 py-0 max-md:w-full">
            <legend class="fieldset-legend mb-0.5 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Ver por</legend>
            <div class="join max-md:grid max-md:w-full max-md:grid-cols-4" role="group" aria-label="Ver por" data-testid="view-filter">
                @foreach (\App\Modules\Solver\Queries\RunComparison::VIEWS as $key => $label)
                    <button type="button" wire:click="$set('view', '{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}"
                        @class(['btn join-item px-3 max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:px-1.5 max-md:leading-tight max-md:whitespace-normal', 'btn-primary' => $view === $key])>{{ $label }}</button>
                @endforeach
            </div>
        </fieldset>
        <fieldset class="fieldset min-w-0 grow py-0 max-md:w-full md:min-w-64">
            <legend class="fieldset-legend mb-0.5 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">{{ $viewLabel }}</legend>
            <label class="select w-full max-md:h-11">
                <select aria-label="{{ $viewLabel }}" wire:model.live="lens" data-testid="lens-select">
                    @forelse ($lensOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }} — {{ $option['changes'] === 0 ? 'sin cambios' : ($option['changes'] === 1 ? '1 cambio' : $option['changes'].' cambios') }}</option>
                    @empty
                        <option value="">Sin opciones en este campus</option>
                    @endforelse
                </select>
            </label>
        </fieldset>
    @endif

    @if ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Applying)
        <span class="flex flex-col gap-1 max-md:w-full md:ms-auto md:items-end">
            <button type="button" class="btn btn-primary btn-disabled max-md:h-11" aria-disabled="true" aria-describedby="accept-why" tabindex="0" data-testid="run-accept-disabled">
                <x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Aceptar corrida
            </button>
            <span id="accept-why" class="flex items-center gap-1 text-xs text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Se está aplicando: espera a que termine.</span>
        </span>
    @endif

    @if ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Done)
        <div class="flex flex-wrap items-center gap-2 max-md:w-full md:ms-auto" data-testid="run-decide">
            @if ($can['discard'])
                <button type="button" class="btn btn-ghost max-md:h-11 max-md:flex-1" wire:click="discard" x-confirm="Queda en el historial como descartada y ya no se puede aceptar. La carga no cambia." data-confirm-title="¿Descartar la corrida {{ $row['number'] }}?" data-confirm-button="Descartar" data-confirm-tone="warning" data-testid="run-discard">
                    <x-icon name="o-archive-box-x-mark" class="h-4 w-4" aria-hidden="true" />Descartar
                </button>
            @endif
            @if ($can['apply'])
                <button type="button" class="btn btn-primary max-md:h-11 max-md:flex-1" wire:click="openAccept" data-testid="run-accept">
                    <x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Aceptar corrida
                </button>
            @else
                <span class="flex flex-col gap-1 max-md:w-full md:items-end">
                    <button type="button" class="btn btn-primary btn-disabled max-md:h-11" aria-disabled="true" aria-describedby="accept-why" tabindex="0" data-testid="run-accept-disabled">
                        <x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Aceptar corrida
                    </button>
                    <span id="accept-why" class="flex items-center gap-1 text-xs text-neutral">
                        <x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $run->scope_kind === \App\Modules\Solver\Enums\RunScope::Career ? 'Una corrida de carrera es para explorar: no se acepta.' : 'Solo el jefe de '.($run->department->name ?? 'su departamento').' acepta una corrida.' }}
                    </span>
                </span>
            @endif
        </div>
    @endif
</div>

<div class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 text-neutral" data-testid="run-facts">
    <span>{{ $engines[$run->engine] ?? $run->engine }} · {{ $when($run->created_at) }} · {{ $run->creator->name ?? '—' }}@if ($output) · tardó {{ $output->metrics->elapsedSeconds < 60 ? round($output->metrics->elapsedSeconds, 1).' s' : intdiv((int) $output->metrics->elapsedSeconds, 60).' min '.((int) $output->metrics->elapsedSeconds % 60).' s' }}@endif</span>
    <x-core::status-badge :tone="$run->status->tone()" :icon="$run->status->icon()" :label="$run->status->label()" />
    @if ($output)
        <button type="button" wire:click="openScore" class="inline-flex items-center rounded-selector leading-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-testid="hard-badge">
            <x-core::status-badge :tone="$hard > 0 ? 'error' : 'success'" :icon="$hard > 0 ? 'o-x-circle' : 'o-check-circle'" :label="$hard === 1 ? '1 dura' : $hard.' duras'" />
        </button>
        <button type="button" wire:click="openScore" class="inline-flex items-center rounded-selector leading-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-testid="score-badge">
            <x-core::status-badge tone="info" icon="o-scale" :label="'Puntaje '.number_format($output->softScore->total)" />
        </button>
    @endif
    @if ($ready)
        @if ($keptCount > 0)
            <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="$keptCount === 1 ? '1 grupo se queda' : $keptCount.' grupos se quedan'" />
        @endif
        <span>Cambian <span class="font-mono font-semibold text-base-content">{{ number_format($stats['changes']) }}</span> de <span class="font-mono font-semibold text-base-content">{{ number_format($stats['total']) }}</span> sesiones en <span class="font-mono font-semibold text-base-content">{{ $stats['groups'] }}</span> grupos</span>
    @endif
</div>

@if ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Done && ($run->metrics['apply_error'] ?? null))
    <div role="alert" class="alert alert-error alert-soft items-start" data-testid="run-apply-error">
        <x-icon name="o-x-circle" class="h-5 w-5 shrink-0" aria-hidden="true" />
        <div class="grid grow gap-1">
            <b>No se pudo aplicar.</b>
            <span class="text-base-content">{{ $run->metrics['apply_error'] }}</span>
        </div>
        @if ($can['launch'])
            <button type="button" class="btn btn-primary btn-sm" wire:click="openLaunch('{{ $run->id }}')"><x-icon name="o-arrow-path" class="h-4 w-4" aria-hidden="true" />Volver a correr</button>
        @endif
    </div>
@endif
