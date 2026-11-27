{{--
    Las horas de más de una corrida (HC-08, tarea 1.50), aparte de los problemas de entrada: lo que las personas
    asignaron de más es aviso con el oficio de conformidad pendiente, no algo que se corrija en la carga ni que retenga
    grupos. Solo lo traen corridas de antes de la tarea 1.50 o entradas armadas a mano. `$notices`
    (RunComparison::hourNotices o el resumen de la aplicada), `$testid`.
--}}
@if ($notices !== [])
    <div role="note" class="flex items-start gap-2 rounded-field border border-base-300 px-3 py-2 text-sm" data-testid="{{ $testid }}">
        <x-icon name="o-document-text" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
        <span><b>Horas de más (aviso):</b> no se corrigen aquí; el oficio de conformidad del docente está pendiente. {{ collect($notices)->pluck('message')->implode(' ') }}</span>
    </div>
@endif
