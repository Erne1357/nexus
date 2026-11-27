{{-- Día, inicio, fin y tipo de una sesión (en los bloques del perfil de la modalidad), con el botón de guardar. --}}
<div class="grid gap-2.5" data-testid="time-form">
    <div class="grid grid-cols-[1.4fr_1fr_1fr] gap-2.5 max-md:grid-cols-2">
        <div class="max-md:col-span-2"><x-select label="Día" wire:model="day" :options="$days" /></div>
        <x-select label="Inicio" wire:model="startsAt" :options="$starts" class="font-mono" />
        <x-select label="Fin" wire:model="endsAt" :options="$ends" class="font-mono" />
    </div>
    <x-select label="Tipo" wire:model="kind" :options="$kinds" />
    <div class="flex gap-2">
        <x-button :label="$label" icon="o-check" class="btn-primary btn-sm" wire:click="{{ $submit }}" spinner="{{ $submit }}" data-testid="save-time" />
        <x-button label="Cancelar" class="btn-ghost btn-sm" wire:click="cancelEdit" />
    </div>
</div>
