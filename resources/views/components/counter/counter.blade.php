<div class="flex items-center gap-4">
    <button type="button" wire:click="decrement" class="rounded border px-3 py-1">-</button>
    <span class="text-xl font-semibold">{{ $count }}</span>
    <button type="button" wire:click="increment" class="rounded border px-3 py-1">+</button>
</div>
