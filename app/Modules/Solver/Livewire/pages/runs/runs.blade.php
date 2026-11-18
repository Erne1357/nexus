{{--
    Corridas del motor (tarea 6.10; variante C «Semana con cambios», `.impeccable/mocks/corridas/c.html`; DESIGN.md,
    «Corridas del motor»):
    - Encabezado: «Corridas del motor» con el periodo, la carga o la carrera (select con más de una), la modalidad, los
      grupos y sesiones de hoy y los grupos por campus; a la derecha «Lanzar corrida» (deshabilitado con su motivo).
    - El aviso de tronco común (SV-20) cuando otra carga que comparte alumnos todavía no tiene horario.
    - El renglón de selectores (solver::runs.controls) y la línea de la corrida elegida (motor, quién, insignias).
    - Según el estado: la semana con la bandeja (solver::runs.compare), el avance de la que corre
      (solver::components.run-progress, con `wire:poll` solo entonces), su falla o la línea de aplicada, descartada o
      cancelada.
    - El historial (solver::runs.history) y los drawers y modales (solver::runs.panels).
--}}
@php
    $when = function ($at): string {
        if ($at === null) {
            return '—';
        }

        $local = $at->timezone(config('app.timezone'));

        return match (true) {
            $local->isToday() => 'Hoy a las '.$local->format('G:i'),
            $local->isYesterday() => 'Ayer a las '.$local->format('G:i'),
            default => 'El '.$local->translatedFormat('j M').' a las '.$local->format('G:i'),
        };
    };
    $scopeText = fn ($run): string => $run->scope_kind === \App\Modules\Solver\Enums\RunScope::Department ? 'Departamento' : 'Carrera '.($run->career->code ?? '');
@endphp
<div class="space-y-4" x-data="{ day: $wire.entangle('day') }" data-testid="solver-runs">
    <header class="flex flex-wrap items-start gap-x-6 gap-y-3 border-b border-base-300 pb-4">
        <div class="min-w-0 grow">
            <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.625rem] leading-tight font-bold md:text-3xl">
                Corridas del motor
                @isset($term)
                    <x-core::status-badge tone="primary" :label="$term->name" />
                @endisset
            </h1>
            @unless ($message)
                <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-neutral" data-testid="runs-facts">
                    @if (count($scopeOptions) > 1)
                        <label class="select select-sm w-auto max-w-full font-semibold text-base-content">
                            <span class="sr-only">{{ $scopeKind === 'department' ? 'Departamento' : 'Carrera' }}</span>
                            <select wire:model.live="{{ $scopeKind === 'department' ? 'department' : 'career' }}" aria-label="{{ $scopeKind === 'department' ? 'Departamento' : 'Carrera' }}" data-testid="runs-scope">
                                @foreach ($scopeOptions as $option)
                                    <option value="{{ $option['code'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <b class="font-semibold text-base-content" data-testid="runs-scope-name">{{ $scopeName }}</b>
                    @endif
                    @if ($modalityOptions->count() <= 1)
                        <span>{{ $shownModality->name }}</span>
                    @else
                        <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality->code" class="w-48" />
                    @endif
                    <span><span class="font-mono text-base-content">{{ number_format($facts['groups']) }}</span> grupos · <span class="font-mono text-base-content">{{ number_format($facts['sessions']) }}</span> sesiones</span>
                    @if (count($facts['campuses']) > 1)
                        <span class="inline-flex items-center gap-2 whitespace-nowrap">
                            @foreach ($facts['campuses'] as $code => $count)
                                <x-core::campus-tag :campus="$campuses[$code] ?? (object) ['code' => $code, 'name' => $code]" /> <span class="font-mono text-base-content">{{ $count }}</span>
                            @endforeach
                        </span>
                    @endif
                </div>
            @endunless
        </div>
        @unless ($message)
            <div class="flex items-center max-md:w-full">
                @if ($run?->status === \App\Modules\Solver\Enums\SolverRunStatus::Applying)
                    <span class="flex flex-col items-end gap-1 max-md:w-full max-md:items-stretch">
                        <button type="button" class="btn btn-disabled max-md:h-11" aria-disabled="true" aria-describedby="launch-why" tabindex="0"><x-icon name="o-play" class="h-4 w-4" aria-hidden="true" />Lanzar corrida</button>
                        <span id="launch-why" class="flex items-center gap-1 text-xs text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Se está aplicando una corrida de esta carga.</span>
                    </span>
                @elseif ($can['launch'])
                    <button type="button" wire:click="openLaunch" @class(['btn max-md:h-11 max-md:w-full', 'btn-primary' => $run === null || ! in_array($run->status, [\App\Modules\Solver\Enums\SolverRunStatus::Done, \App\Modules\Solver\Enums\SolverRunStatus::Failed], true)]) data-testid="launch-open">
                        <x-icon name="o-play" class="h-4 w-4" aria-hidden="true" />Lanzar corrida
                    </button>
                @else
                    <span class="flex flex-col items-end gap-1 max-md:w-full max-md:items-stretch">
                        <button type="button" class="btn btn-disabled max-md:h-11" aria-disabled="true" aria-describedby="launch-why" tabindex="0">
                            <x-icon name="o-play" class="h-4 w-4" aria-hidden="true" />Lanzar corrida
                        </button>
                        <span id="launch-why" class="flex items-center gap-1 text-xs text-neutral"><x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Las lanzan el jefe del departamento y los coordinadores.</span>
                    </span>
                @endif
            </div>
        @endunless
    </header>

    @if ($message)
        <div class="flex flex-col items-center gap-2 rounded-box border border-dashed border-base-300 bg-base-100 px-5 py-10 text-center" data-testid="runs-message">
            <x-icon name="o-cpu-chip" class="h-6 w-6 text-neutral" aria-hidden="true" />
            <p class="max-w-prose text-neutral">{{ $message }}</p>
        </div>
    @else
        @include('solver::runs.trunk', ['compact' => true])

        @if ($run === null)
            <section class="flex flex-col items-center gap-3 rounded-box border border-base-300 bg-base-100 px-5 py-12 text-center" data-testid="runs-empty">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-base-200 text-neutral"><x-icon name="o-cpu-chip" class="h-6 w-6" aria-hidden="true" /></span>
                <h2 class="text-lg font-bold">Todavía no hay corridas de {{ $scopeKind === 'department' ? 'esta carga' : 'esta carrera' }}.</h2>
                <p class="max-w-prose text-neutral">El motor propone el horario de los <span class="font-mono">{{ number_format($facts['groups']) }}</span> grupos en todos sus campus y lo compara con lo que hay hoy en el editor. Nada se escribe hasta que el jefe del departamento lo acepta.</p>
                @if ($can['launch'])
                    <button type="button" class="btn btn-primary max-md:h-11" wire:click="openLaunch"><x-icon name="o-play" class="h-4 w-4" aria-hidden="true" />Lanzar corrida</button>
                @else
                    <p class="flex items-center gap-1.5 text-sm text-neutral"><x-icon name="o-lock-closed" class="h-4 w-4" aria-hidden="true" />Las lanzan el jefe del departamento y los coordinadores.</p>
                @endif
            </section>
        @else
            @include('solver::runs.controls')

            @if ($run->status->isInProgress())
                <section class="grid gap-3.5 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" data-testid="run-live">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-bold">Corrida <span class="font-mono">{{ $row['number'] }}</span> · {{ $run->scope_kind === \App\Modules\Solver\Enums\RunScope::Department ? 'toda la carga del departamento' : 'carrera '.($run->career->code ?? '') }}</h2>
                        @if ($can['cancel'])
                            <button type="button" class="btn btn-ghost text-error max-md:h-11" wire:click="cancel" x-confirm="Se detiene ahora y no guarda resultado; queda en el historial como cancelada." data-confirm-title="¿Cancelar la corrida {{ $row['number'] }}?" data-confirm-button="Cancelar corrida" data-confirm-tone="error" data-testid="run-cancel">
                                <x-icon name="o-stop-circle" class="h-4 w-4" aria-hidden="true" />Cancelar corrida
                            </button>
                        @endif
                    </div>
                    <livewire:solver::components.run-progress :run-id="$run->id" :wire:key="'progress-'.$run->id" />
                    <p class="text-sm text-neutral">La semana aparece cuando termina; mientras, la carga no cambia.</p>
                </section>
            @elseif ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Applying)
                <section class="grid gap-3 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" data-testid="run-applying">
                    <h2 class="text-lg font-bold">Corrida <span class="font-mono">{{ $row['number'] }}</span> · {{ $scopeText($run) }}</h2>
                    <livewire:solver::components.run-progress :run-id="$run->id" :wire:key="'progress-'.$run->id" />
                </section>
            @elseif ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Failed)
                <section class="grid gap-3 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" data-testid="run-failed">
                    <h2 class="text-lg font-bold">Corrida <span class="font-mono">{{ $row['number'] }}</span> · {{ $scopeText($run) }}</h2>
                    <div role="alert" class="alert alert-error alert-soft items-start">
                        <x-icon name="o-x-circle" class="h-5 w-5 shrink-0" aria-hidden="true" />
                        <div class="grid gap-1">
                            <b>No terminó.</b>
                            <span class="text-base-content">{{ $run->error ?: 'La corrida no terminó.' }} Vuelve a correrla; si se repite, avisa a administración del sistema.</span>
                        </div>
                    </div>
                    @if ($can['launch'])
                        <div><button type="button" class="btn btn-primary max-md:h-11 max-md:w-full" wire:click="openLaunch('{{ $run->id }}')"><x-icon name="o-arrow-path" class="h-4 w-4" aria-hidden="true" />Volver a correr</button></div>
                    @endif
                </section>
            @elseif ($run->status !== \App\Modules\Solver\Enums\SolverRunStatus::Done)
                <div role="status" @class(['alert alert-soft', 'alert-success' => $run->status === \App\Modules\Solver\Enums\SolverRunStatus::Applied]) data-testid="run-closed">
                    <x-icon :name="$run->status->icon()" class="h-5 w-5 shrink-0" aria-hidden="true" />
                    <span class="grow text-base-content">
                        @if ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Applied)
                            Aplicada {{ mb_strtolower($when($run->applied_at)) }} por {{ $run->applier->name ?? 'alguien' }}{!! ($decision['summary'] ?? null) ? ': <span class="font-mono">'.e($decision['summary']['sessions'] ?? 0).'</span> sesiones escritas en <span class="font-mono">'.e($decision['summary']['groups'] ?? 0).'</span> grupos' : '' !!}.
                            @if (($decision['summary']['input_problems'] ?? []) !== [])
                                <span data-testid="applied-input-problems"><b>Problemas de entrada por corregir:</b> {{ collect($decision['summary']['input_problems'])->map(fn ($problem) => $problem['rule'].' · '.$problem['message'])->implode('; ') }}.</span>
                            @endif
                        @elseif ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Discarded)
                            Descartada {{ mb_strtolower($when($decision['at'] ?? $run->updated_at)) }}{{ ($decision['who'] ?? null) ? ' por '.$decision['who'] : '' }}.
                        @else
                            Cancelada {{ mb_strtolower($when($decision['at'] ?? $run->finished_at ?? $run->updated_at)) }}{{ ($decision['who'] ?? null) ? ' por '.$decision['who'] : '' }}.
                        @endif
                        Su semana ya no se compara con la carga actual.
                    </span>
                    @if ($output)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="openScore">Ver su puntaje</button>
                    @endif
                </div>
            @elseif ($comparison === null)
                <p class="alert alert-soft" role="status"><x-icon name="o-information-circle" class="h-5 w-5" aria-hidden="true" />Esta corrida no guardó su resultado: no hay semana que comparar.</p>
            @else
                @include('solver::runs.compare')
            @endif

            @include('solver::runs.history')
        @endif

        @include('solver::runs.panels')
    @endif
</div>
