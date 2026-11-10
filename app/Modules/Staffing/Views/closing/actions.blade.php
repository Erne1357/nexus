{{--
    Las acciones de una carga (ClosesLoads::loadActions): reabrir o regresar con comentarios en outline, y la principal
    (cerrar, volver a asignación, dar el visto bueno o publicar) en primary. Lo que el rol o el estado no permite queda
    deshabilitado, con su motivo en `title` y escrito debajo (DESIGN.md, «Nada oculto»), salvo si ya lo dice la línea del
    estado. En celular los botones ocupan el ancho. `$actions`, `$load` y `$prefix` (para los ids).
--}}
@php($methods = ['close' => 'askClose', 'reopen' => 'askReopen', 'return' => 'returnToStaffing', 'approve' => 'askApprove'])
@php($written = collect($actions)->filter(fn (array $action): bool => $action['reason'] !== null && ! $action['explained'])->values())
<div class="flex min-w-0 flex-col items-stretch gap-1.5 md:items-end">
    <div class="flex flex-wrap gap-2 max-md:w-full md:justify-end">
        @foreach ($actions as $action)
            @if ($action['key'] === 'publish')
                {{--
                    Publicar (tarea 4.3): aquí se monta el componente del Riel A,
                    <livewire:academic-load::components.publish-load :load="$load" wire:key="publish-{{ $load->id }}" />,
                    que avisa con `load-published`. Mientras no esté en develop, el botón queda deshabilitado con su motivo.
                --}}
                <button type="button" disabled class="btn btn-primary max-md:h-11 max-md:flex-1" title="{{ $action['reason'] }}" aria-describedby="{{ $prefix }}-reason-publish" data-testid="action-publish">
                    <x-icon :name="$action['icon']" class="h-5 w-5" aria-hidden="true" />{{ $action['label'] }}
                </button>
            @else
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
            @endif
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
