{{--
    El tablero (variante C): la tarjeta de avance (HC-14) y una tarjeta por revisión, las que tienen trabajo primero
    (review.php). Toda la tarjeta abre la lista: los indicadores de docentes (readiness) son enlaces a la lista
    filtrada; el título es un enlace estirado con `after:absolute after:inset-0` y los botones van encima
    (`relative z-10`), así no hay botones anidados en un enlace ni un «Abrir lista» que repita el destino. Reabrir va
    deshabilitado con su motivo escrito cuando la carga ya salió de borrador (CV5); quien solo lee (Subdirección,
    admin) ve Validar y Reabrir deshabilitados con su motivo.
--}}
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="review-board">
    <section class="space-y-3.5 rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="review-progress" data-testid="review-progress">
        <div class="flex items-baseline gap-2.5">
            <p id="review-progress" class="text-4xl font-semibold leading-none">{{ $progress['validated'] }} de {{ $progress['total'] }}</p>
            <p class="text-neutral">validados</p>
        </div>

        <div class="flex gap-1" aria-hidden="true">
            @foreach ($progress['items'] as $item)
                <i @class(['h-2.5 flex-1 rounded-sm', 'bg-success' => $item['review']?->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated, 'bg-info' => $item['review']?->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview, 'bg-warning/50' => ! in_array($item['review']?->status, [\App\Modules\CatalogValidation\Enums\ReviewStatus::Validated, \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview], true)])></i>
            @endforeach
        </div>

        <ul class="space-y-2 text-sm max-md:hidden">
            @foreach ($progress['items'] as $item)
                <li class="flex items-center justify-between gap-3">
                    @if ($item['review'])
                        <a href="{{ route('catalog-validation.review', ['department' => $department->id, 'catalogo' => $item['catalog']->value]) }}" wire:click.prevent="$set('catalog', '{{ $item['catalog']->value }}')" class="underline decoration-base-300 underline-offset-4 hover:decoration-current">{{ $item['catalog']->label() }}</a>
                        <span class="text-neutral">{{ $item['review']->status->label() }}</span>
                    @else
                        <span>{{ $item['catalog']->label() }}</span>
                        <span class="text-neutral">Sin revisión</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($progress['left'] > 0)
            <p class="flex items-center gap-2 text-sm font-medium text-warning" data-testid="review-lock"><x-icon name="o-lock-closed" class="h-4 w-4" aria-hidden="true" />{{ trans_choice('{1} Falta 1 para sacar la carga de borrador|[2,*] Faltan :count para sacar la carga de borrador', $progress['left']) }}</p>
        @else
            <p class="flex items-center gap-2 text-sm font-medium text-success" data-testid="review-lock"><x-icon name="o-lock-open" class="h-4 w-4" aria-hidden="true" />Todo validado: la carga puede salir de borrador</p>
        @endif

        @if ($firstTerm)
            <p class="text-sm text-neutral" data-testid="first-term">Primer periodo en Nexus</p>
        @endif
    </section>

    @foreach ($cards as $card)
        @php($review = $card['review'])
        <article wire:key="card-{{ $review->catalog->value }}" class="relative flex flex-col gap-3.5 rounded-box border border-base-300 bg-base-100 p-4 transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary hover:bg-base-200/60" data-testid="card-{{ $review->catalog->value }}">
            <header class="flex items-start justify-between gap-2.5">
                <div class="min-w-0">
                    <h2 class="text-xl font-bold leading-tight">
                        <a href="{{ route('catalog-validation.review', ['department' => $department->id, 'catalogo' => $review->catalog->value]) }}" wire:click.prevent="$set('catalog', '{{ $review->catalog->value }}')" class="after:absolute after:inset-0 focus-visible:outline-none" data-testid="open-{{ $review->catalog->value }}">{{ $review->catalog->label() }}</a>
                    </h2>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-neutral">
                        <x-icon :name="$review->catalog->isGeneral() ? 'o-building-library' : 'o-map-pin'" class="h-4 w-4" aria-hidden="true" />
                        {{ $review->catalog->isGeneral() ? 'General · todo el Tec' : $review->campus?->name }}
                    </p>
                </div>
                <x-core::status-badge :tone="$review->status->tone()" :label="$review->status->label()" />
            </header>

            @include('catalog-validation::review.who', ['review' => $review])
            @include('catalog-validation::review.counters', ['summary' => $card['summary'], 'firstTerm' => $firstTerm])
            @include('catalog-validation::review.reviewed-count', ['card' => $card])

            @if ($card['readiness'] !== null)
                @include('catalog-validation::review.readiness', ['counts' => $card['readiness'], 'term' => $term, 'department' => $department])
            @endif

            <div class="relative z-10 mt-auto flex gap-2">
                @include('catalog-validation::review.actions', ['card' => $card])
            </div>

            @foreach ($card['reopenVetoes'] as $veto)
                <p class="relative z-10 text-xs text-neutral" data-testid="reopen-veto-{{ $review->catalog->value }}">{{ $veto }}</p>
            @endforeach
            @if ($card['canValidate'] && $card['validateReason'] !== null && $review->status !== \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
                <p class="relative z-10 text-xs text-neutral" data-testid="validate-reason-{{ $review->catalog->value }}">{{ $card['validateReason'] }}</p>
            @endif
        </article>
    @endforeach
</div>
