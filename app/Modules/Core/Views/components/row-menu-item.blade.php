{{--
    Elemento de <x-core::row-menu>. Lo deshabilitado se queda en el menú con su motivo escrito en un
    segundo renglón (`reason`): nada se oculta por permiso y un tooltip no lo ve quien usa teclado o
    pantalla táctil. Solo el elemento habilitado lleva los atributos de acción (wire:click,
    x-confirm y sus data-confirm-*, data-testid; nunca wire:confirm, ADR-0023); con `href` es un enlace (wire:navigate) en lugar de un botón; `spinner` es el objetivo de wire:target; `danger` lo pinta en text-error.
--}}
@props(['label', 'reason' => null, 'disabled' => false, 'danger' => false, 'spinner' => null, 'href' => null])

@php($tag = $href !== null && ! $disabled ? 'a' : 'button')

<{{ $tag }}
    @if ($tag === 'a') href="{{ $href }}" wire:navigate @else type="button" @endif
    role="menuitem"
    @if ($disabled) aria-disabled="true" @else x-on:click="close()" @endif
    {{ $attributes->class([
        'flex w-full flex-col items-start rounded-field px-3 py-2 text-start text-sm focus-visible:outline-none',
        'cursor-not-allowed text-neutral' => $disabled,
        'cursor-pointer hover:bg-base-200 focus-visible:bg-base-200' => ! $disabled,
        'text-error' => $danger && ! $disabled,
    ]) }}
>
    <span class="flex w-full items-center gap-2 font-medium">
        {{ $label }}
        @if ($spinner !== null && ! $disabled)
            <span class="loading loading-spinner loading-xs ms-auto" wire:loading wire:target="{{ $spinner }}"></span>
        @endif
    </span>

    @if ($reason !== null)
        <span class="text-xs font-normal text-neutral">{{ $reason }}</span>
    @endif
</{{ $tag }}>
