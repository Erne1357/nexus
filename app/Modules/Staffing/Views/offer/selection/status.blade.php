{{--
    El estado de un docente en el orden de selección como insignia (maqueta B): En turno, Terminó, Elección previa (SEL-16), Ronda extra, Sin
    resolver o Esperando. Espera $status (valor de SelectionEntryStatus) y $inTurn; con elección previa, `$confirmation` (opcional: `pending`
    «Falta confirmar» o `confirmed` «Confirmó», SEL-16 ampliada).
--}}
@php
    [$tone, $icon, $label] = match (true) {
        $inTurn => ['info', 'o-clock', 'En turno'],
        $status === 'prior_choice' && ($confirmation ?? null) === 'pending' => ['warning', 'o-exclamation-triangle', 'Elección previa · falta confirmar'],
        $status === 'prior_choice' && ($confirmation ?? null) === 'confirmed' => ['success', 'o-check-circle', 'Elección previa · confirmó'],
        $status === 'prior_choice' => ['success', 'o-check-circle', 'Elección previa'],
        $status === 'done' => ['success', 'o-check-circle', 'Terminó'],
        $status === 'pending_extra' => ['warning', 'o-arrow-path', 'Ronda extra'],
        $status === 'unresolved' => ['error', 'o-exclamation-circle', 'Sin resolver'],
        default => ['neutral', 'o-calendar', 'Esperando'],
    };
@endphp
<x-core::status-badge :tone="$tone" :icon="$icon" :label="$label" class="badge-sm" />
