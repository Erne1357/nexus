{{-- La insignia del estatus de un alumno (DESIGN.md, "Insignias de estado"); sin estatus, la de borrador con su aviso. --}}
<x-core::status-badge
    :tone="$status?->tone() ?? 'draft'"
    :label="$status?->label() ?? 'Sin estatus'"
    :icon="$status === null ? 'o-exclamation-triangle' : $status->icon()"
/>
