{{--
    «Evaluar un periodo» (btn-primary, la acción principal de la pantalla): abre su drawer. A quien ve el reporte pero no
    lanza (sin `academic_load.forecast_evaluation.run`) le sale deshabilitado con su motivo. `$canRun`; `$class` opcional.
--}}
<x-button label="Evaluar un periodo" icon="o-plus" wire:click="openEvaluate" spinner="openEvaluate"
    @class(['btn-primary', $class ?? ''])
    :disabled="! $canRun"
    :title="$canRun ? null : 'No tienes permiso para evaluar la proyección.'"
    data-testid="open-evaluate" />
