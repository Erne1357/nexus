{{--
    DM-27: los alumnos con kárdex del periodo cuya carrera no se proyecta o cuyo campus no tiene oferta de su carrera no se
    evalúan. Solo si hay alguno. `$current` (ForecastEvaluationItem).
--}}
@if ($current->outOfScopeStudents > 0)
    <p class="flex items-start gap-2 text-sm text-neutral" data-testid="out-of-scope">
        <x-icon name="o-information-circle" class="mt-px h-4 w-4 shrink-0" aria-hidden="true" />
        <span><span class="font-mono tabular-nums">{{ number_format($current->outOfScopeStudents) }}</span> {{ $current->outOfScopeStudents === 1 ? 'alumno de carreras o campus sin oferta no se evalúa.' : 'alumnos de carreras o campus sin oferta no se evalúan.' }}</span>
    </p>
@endif
