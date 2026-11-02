{{--
    Los campos del formulario de un periodo, los mismos en el modal de core::pages.terms (alta y edición) y en el
    de core::pages.term (edición desde la ficha). Las propiedades del componente se llaman igual en los dos:
    code, name, starts_on y ends_on.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-input label="Código" name="code" wire:model.live.debounce.300ms="code" placeholder="20271" hint="Año y periodo: 1 Ene-Jun, 2 Verano, 3 Ago-Dic." />
    <x-input label="Nombre" name="name" wire:model="name" />
    <x-input label="Inicio" name="starts_on" type="date" wire:model="starts_on" />
    <x-input label="Fin" name="ends_on" type="date" wire:model="ends_on" />
</div>
