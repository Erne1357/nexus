{{--
    El avance de la corrida en curso (solver::components.run-progress): en cola, una línea; corriendo, el avance, el mejor
    puntaje hasta ahora y el tiempo contra su límite. Se lee cada 2 segundos solo mientras la corrida está en curso
    (`wire:poll`); al terminar, la página se vuelve a pintar con su resultado.
--}}
<div @if ($live) wire:poll.2s @endif data-testid="run-progress">
    @if (! $live)
        <p class="text-neutral">La corrida terminó.</p>
    @elseif ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Applying)
        <p class="flex items-center gap-2 text-neutral" role="status"><x-icon name="o-arrow-path" class="h-5 w-5 shrink-0 animate-spin" aria-hidden="true" />Aplicando…  Se escribe el horario en segundo plano; la campana avisa al terminar.</p>
    @elseif ($run->status === \App\Modules\Solver\Enums\SolverRunStatus::Queued)
        <p class="flex items-center gap-2 text-neutral"><x-icon name="o-clock" class="h-5 w-5 shrink-0" aria-hidden="true" />En cola: empieza en cuanto la tome la cola.</p>
    @else
        <dl class="grid overflow-hidden rounded-box border border-base-300 md:grid-cols-3">
            <div class="grid gap-1 px-4 py-3">
                <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Avance</dt>
                <dd class="font-mono text-2xl font-semibold">{{ $run->progress }}<small class="text-sm font-normal text-neutral"> %</small></dd>
                <progress class="progress progress-primary h-2 w-full" value="{{ $run->progress }}" max="100" aria-label="Avance de la corrida"></progress>
            </div>
            <div class="grid gap-1 border-base-300 px-4 py-3 max-md:border-t md:border-l">
                <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Mejor puntaje hasta ahora</dt>
                <dd class="font-mono text-2xl font-semibold">{{ $run->best_score === null ? '—' : number_format($run->best_score) }}</dd>
                <p class="text-sm text-neutral">Menos es mejor.</p>
            </div>
            <div class="grid gap-1 border-base-300 px-4 py-3 max-md:border-t md:border-l">
                <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Tiempo</dt>
                <dd class="font-mono text-2xl font-semibold">{{ gmdate('i:s', min($elapsed, 5999)) }}<small class="text-sm font-normal text-neutral"> de {{ gmdate('i:s', $limit) }}</small></dd>
                @if ($run->cancel_requested_at)
                    <p class="text-sm text-warning">Se pidió cancelarla: se detiene en su siguiente aviso.</p>
                @endif
            </div>
        </dl>
    @endif
</div>
