{{--
    «Copiar de otro periodo» (core::pages.term): abre el modal de copia (`openCopy`). Se pinta en el estado sin
    calendario (`$variant` «primary» lo deja como acción del vacío) y, discreto, en el encabezado de «Calendario».
    Deshabilitado con su motivo si ningún otro periodo tiene calendario o el usuario no administra el calendario;
    el motivo va en un tooltip y en `title`. Usa `$copySources` y `$canManageCalendar` de la página.
--}}
@props(['variant' => 'ghost'])

@php($reason = ! $canManageCalendar ? 'Solo el administrador o la subdirección editan el calendario.' : ($copySources->isEmpty() ? 'Ningún otro periodo tiene calendario.' : null))

@if ($reason === null)
    <x-button label="Copiar de otro periodo" icon="o-document-duplicate" :class="$variant === 'outline' ? 'btn-outline btn-sm max-md:h-11' : 'btn-ghost btn-sm max-md:h-11'" wire:click="openCopy" spinner="openCopy" data-testid="copy-calendar" />
@else
    <span class="tooltip" data-tip="{{ $reason }}">
        <x-button label="Copiar de otro periodo" icon="o-document-duplicate" :class="$variant === 'outline' ? 'btn-outline btn-sm max-md:h-11' : 'btn-ghost btn-sm max-md:h-11'" disabled data-testid="copy-calendar" />
    </span>
@endif
