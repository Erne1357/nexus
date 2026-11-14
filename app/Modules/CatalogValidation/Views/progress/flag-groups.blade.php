{{--
    Las observaciones abiertas de un departamento, por catálogo y registro, en el drawer del departamento y en
    «Observaciones abiertas» (CV10). Cada registro enlaza a su catálogo para actuar (ValidationProgressQuery::recordUrl), si
    quien mira puede verlo, y cada observación tiene «Marcar como resuelta» con la nota de lo que se hizo
    (CatalogReviewPolicy::resolveFlag). `$flags` son observaciones de un solo departamento, con su revisión y registro.
--}}
@foreach ($flags->groupBy('review_id') as $catalogFlags)
    @php($review = $catalogFlags->first()->review)
    <div class="space-y-2" wire:key="flag-group-{{ $review->id }}" data-testid="drawer-review-flags-{{ $review->id }}">
        <p class="text-sm font-semibold">{{ $review->catalog->label() }} <span class="font-mono font-normal text-neutral tabular-nums">{{ $catalogFlags->count() }}</span></p>
        @foreach ($catalogFlags->groupBy(fn ($flag) => $flag->flaggable_type.'|'.$flag->flaggable_id) as $recordFlags)
            @php($record = $recordFlags->first()->flaggable)
            @php($recordUrl = \App\Modules\CatalogValidation\Queries\ValidationProgressQuery::recordUrl($record))
            <article wire:key="record-{{ $recordFlags->first()->flaggable_type }}-{{ $recordFlags->first()->flaggable_id }}" class="space-y-2 rounded-box border border-base-300 bg-base-100 p-3.5" data-testid="drawer-record-{{ \Illuminate\Support\Str::kebab(class_basename($recordFlags->first()->flaggable_type)) }}-{{ $recordFlags->first()->flaggable_id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <p class="font-semibold"><span class="font-mono">{{ $record?->code ?? $record?->rfc }}</span>@if ($record === null) · Registro ya no disponible @elseif (filled($record->name)) · {{ $record->name }}@endif</p>
                    @if ($recordUrl !== null)
                        @can('view', $record)
                            <a href="{{ $recordUrl }}" wire:navigate class="link text-sm underline-offset-4" data-testid="record-link-{{ $record->getKey() }}">Ir a {{ $review->catalog->label() }}</a>
                        @endcan
                    @endif
                </div>
                <ul class="divide-y divide-base-300">
                    @foreach ($recordFlags as $flag)
                        <li wire:key="flag-{{ $flag->id }}" class="space-y-1.5 py-2 first:pt-0 last:pb-0" data-testid="drawer-flag-{{ $flag->id }}">
                            <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="$flag->flag->label()" />
                            @if ($flag->comment)
                                <p class="text-sm">{{ $flag->comment }}</p>
                            @endif
                            <p class="text-xs text-neutral">{{ $flag->flagger?->name ?? 'Un usuario ya dado de baja' }} · <span class="tabular-nums">{{ $flag->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span></p>
                            @can('resolveFlag', $flag->review)
                                @include('catalog-validation::flag-resolve', ['flag' => $flag, 'testid' => 'resolve-flag-'.$flag->id])
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>
@endforeach
