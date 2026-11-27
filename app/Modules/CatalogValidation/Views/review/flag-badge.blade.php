{{-- La insignia de observación abierta de una fila: la primera y, si hay más, cuántas (`$flags`, lista de ReviewFlag). --}}
@if ($flags === [])
    <span class="text-neutral" aria-label="Sin observaciones abiertas">—</span>
@else
    <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="$flags[0]->label().(count($flags) > 1 ? ' +'.(count($flags) - 1) : '')" />
@endif
