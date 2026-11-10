{{--
    Las acciones de una carga (ClosesLoads::loadActions): reabrir o regresar con comentarios en outline, y la principal
    (cerrar, volver a asignación o dar el visto bueno) en primary. Publicar no va aquí: es el bloque de la publicación
    (staffing::closing.publication). Lo que el rol o el estado no permite queda deshabilitado, con su motivo en `title`
    y escrito debajo (DESIGN.md, «Nada oculto»), salvo si ya lo dice la línea del estado. En celular los botones ocupan
    el ancho. `$actions`, `$load` y `$prefix` (para los ids).
--}}
@php($methods = ['close' => 'askClose', 'reopen' => 'askReopen', 'return' => 'returnToStaffing', 'approve' => 'askApprove'])
@php($written = collect($actions)->filter(fn (array $action): bool => $action['reason'] !== null && ! $action['explained'])->values())
<div class="flex min-w-0 flex-col items-stretch gap-1.5 md:items-end">
    <div class="flex flex-wrap gap-2 max-md:w-full md:justify-end">
        @foreach ($actions as $action)
            <x-button
                :label="$action['label']"
                :icon="$action['icon']"
                @class(['max-md:h-11 max-md:flex-1', 'btn-primary' => $action['primary'], 'btn-outline' => ! $action['primary']])
                wire:click="{{ $methods[$action['key']] }}"
                spinner="{{ $methods[$action['key']] }}"
                :disabled="$action['reason'] !== null"
                :title="$action['reason']"
                :aria-describedby="$action['reason'] !== null && ! $action['explained'] ? $prefix.'-reason-'.$action['key'] : null"
                data-testid="action-{{ $action['key'] }}"
            />
        @endforeach
    </div>
    @if ($written->isNotEmpty())
        <ul class="text-sm text-neutral md:text-end" data-testid="action-reasons">
            @foreach ($written as $action)
                <li id="{{ $prefix }}-reason-{{ $action['key'] }}">{{ $action['reason'] }}</li>
            @endforeach
        </ul>
    @endif
</div>
