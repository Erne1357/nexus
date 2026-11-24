{{--
    El estado de un docente en el orden de selección como insignia (maqueta B): En turno, Terminó, Ronda extra, Sin
    resolver o Esperando. Espera $status (valor de SelectionEntryStatus) y $inTurn.
--}}
@php
    [$tone, $icon, $label] = match (true) {
        $inTurn => ['info', 'o-clock', 'En turno'],
        $status === 'done' => ['success', 'o-check-circle', 'Terminó'],
        $status === 'pending_extra' => ['warning', 'o-arrow-path', 'Ronda extra'],
        $status === 'unresolved' => ['error', 'o-exclamation-circle', 'Sin resolver'],
        default => ['neutral', 'o-calendar', 'Esperando'],
    };
@endphp
<x-core::status-badge :tone="$tone" :icon="$icon" :label="$label" class="badge-sm" />
