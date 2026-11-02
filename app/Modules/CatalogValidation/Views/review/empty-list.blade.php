{{-- Una lista sin resultados: lo dice y, si hay filtros puestos, ofrece quitarlos. `$hasFilters` es de review.php. --}}
<div class="flex flex-col items-center gap-2 py-4 text-center">
    <x-icon name="o-clipboard-document-check" class="h-6 w-6 text-neutral" aria-hidden="true" />
    <p class="text-neutral">Ningún registro coincide.</p>
    @if ($hasFilters)
        <button type="button" class="btn btn-outline btn-sm" wire:click="clearFilters" data-testid="clear-filters">Quitar filtros</button>
    @endif
</div>
