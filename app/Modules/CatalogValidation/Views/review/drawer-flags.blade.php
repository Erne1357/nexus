{{-- Las observaciones de un registro en el drawer (catalog-validation::review.drawer): `$card`, `$editing`, `$flagOptions`. --}}
<section class="space-y-3" aria-labelledby="drawer-flags" data-testid="flags-section">
    <h3 id="drawer-flags" class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
        Observaciones
        <span class="tracking-normal text-base-content"><span class="font-mono">{{ $editing['flags']->whereNull('resolved_at')->count() }}</span> {{ trans_choice('{1} abierta|[0,*] abiertas', $editing['flags']->whereNull('resolved_at')->count()) }}</span>
    </h3>

    @forelse ($editing['flags'] as $item)
        <article wire:key="flag-{{ $item->id }}" @class(['space-y-1.5 rounded-box border p-3 text-sm', 'border-warning/50 bg-warning/10' => $item->resolved_at === null, 'border-base-300 text-neutral' => $item->resolved_at !== null])>
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <x-core::status-badge :tone="$item->resolved_at === null ? 'warning' : 'neutral'" icon="o-chat-bubble-left-ellipsis" :label="$item->flag->label()" />
                <span>{{ $item->flagger?->name }} · <span class="tabular-nums">{{ $item->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}</span></span>
            </p>
            @if ($item->comment)
                <p>{{ $item->comment }}</p>
            @endif
            @if ($item->resolved_at !== null)
                <p class="text-xs">Resuelta por {{ $item->resolver?->name }} · <span class="tabular-nums">{{ $item->resolved_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}</span></p>
            @elseif ($card['canResolve'])
                <x-button label="Marcar como resuelta" icon="o-check" class="btn-outline btn-sm" wire:click="resolveFlag('{{ $item->id }}')" spinner="resolveFlag('{{ $item->id }}')" data-testid="resolve-flag" />
            @endif
        </article>
    @empty
        <p class="text-sm text-neutral">Este registro no tiene observaciones.</p>
    @endforelse

    @if ($card['canFlag'])
        <form wire:submit="addFlag" class="space-y-3 border-t border-dashed border-base-300 pt-4" data-testid="flag-form">
            <x-select label="Tipo" name="flag" wire:model="flag" :options="$flagOptions" placeholder="Elige el tipo" placeholder-value="" />
            <x-textarea label="Comentario" name="comment" wire:model="comment" rows="3" />
            <x-button label="Agregar observación" type="submit" icon="o-chat-bubble-left-ellipsis" class="btn-outline btn-sm" spinner="addFlag" data-testid="add-flag" />
        </form>
    @elseif ($card['flagReason'] !== null)
        <p class="text-sm text-neutral">{{ $card['flagReason'] }}</p>
    @endif
</section>
