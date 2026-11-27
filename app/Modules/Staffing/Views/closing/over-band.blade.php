{{--
    En un renglón de «Avance del cierre»: cuántos docentes de las cargas del departamento pasan su banda de horas frente
    a grupo (HC-08, tarea 1.50), de los choques guardados. Es aviso: no cuenta como faltante ni impide cerrar. Sin
    ninguno, nada. `$n`, `$code`.
--}}
@if ($n > 0)
    <span class="mt-0.5 flex items-center gap-1 text-xs text-warning" data-testid="board-over-band-{{ $code }}">
        <x-icon name="o-document-text" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span><span class="font-mono tabular-nums">{{ $n }}</span> con honorarios por autorizar · aviso</span>
    </span>
@endif
