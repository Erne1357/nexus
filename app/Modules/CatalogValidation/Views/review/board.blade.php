{{--
    El tablero (variante C, con la revisión por registro de la opción A): la tarjeta de avance (HC-14) y una tarjeta por
    revisión del periodo, las que tienen trabajo primero (review.php). La de avance dice, por catálogo exigido, cuántos
    van revisados, «Listo para validar» o «Validado». Cada tarjeta lleva «N de M revisados» en grande con su barra y lo
    que queda (CV7), las cifras, los avisos de docentes (readiness) y «Validar catálogo» a todo el ancho: deshabilitado
    con su motivo escrito debajo mientras falte revisar alguno, o «Reabrir» si ya está validada (deshabilitado con su
    motivo si la carga salió de borrador, CV5). Toda la tarjeta abre la lista: el título es un enlace estirado con
    `after:absolute after:inset-0` y los botones y avisos van encima (`relative z-10`), así no hay botones dentro de un
    enlace.
--}}
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="review-board">
    <section class="space-y-3.5 rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="review-progress" data-testid="review-progress">
        <p id="review-progress" class="flex items-baseline gap-2.5">
            <span class="text-3xl font-semibold leading-none tabular-nums">{{ $progress['validated'] }} de {{ $progress['total'] }}</span>
            <span class="text-neutral">validados</span>
        </p>

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
                        <span class="text-neutral tabular-nums">
                            @if ($item['review']->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
                                Validado
                            @elseif ($item['reviewed'] !== null && $item['reviewed']['reviewed'] >= $item['reviewed']['total'])
                                Listo para validar
                            @elseif ($item['reviewed'] !== null)
                                <span class="font-mono">{{ $item['reviewed']['reviewed'] }}/{{ $item['reviewed']['total'] }}</span> revisados
                            @endif
                        </span>
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
        @php($validated = $review->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
        <article wire:key="card-{{ $review->catalog->value }}" class="relative flex flex-col gap-3.5 rounded-box border border-base-300 bg-base-100 p-4 transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary hover:bg-base-200/60" data-testid="card-{{ $review->catalog->value }}">
            <header class="flex items-start justify-between gap-2.5">
                <div class="min-w-0">
                    <h2 class="text-xl font-bold leading-tight">
                        <a href="{{ route('catalog-validation.review', ['department' => $department->id, 'catalogo' => $review->catalog->value]) }}" wire:click.prevent="$set('catalog', '{{ $review->catalog->value }}')" class="after:absolute after:inset-0 focus-visible:outline-none" data-testid="open-{{ $review->catalog->value }}">{{ $review->catalog->label() }}</a>
                    </h2>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-neutral">
                        <x-icon name="o-building-library" class="h-4 w-4" aria-hidden="true" />
                        {{ $review->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms ? 'Todos los campus' : 'General · todo el Tec' }}
                    </p>
                </div>
                <x-core::status-badge :tone="$review->status->tone()" :label="$review->status->label()" />
            </header>

            @if ($validated)
                @include('catalog-validation::review.who', ['review' => $review])
            @else
                @include('catalog-validation::review.reviewed-count', ['card' => $card, 'big' => true])
            @endif
            @include('catalog-validation::review.counters', ['summary' => $card['summary'], 'firstTerm' => $firstTerm])

            @if ($card['readiness'] !== null)
                @include('catalog-validation::review.readiness', ['counts' => $card['readiness'], 'term' => $term, 'department' => $department])
            @endif

            <div class="relative z-10 mt-auto space-y-2">
                <div class="flex gap-2">
                    @include('catalog-validation::review.actions', ['card' => $card, 'suffix' => '-card'])
                </div>
                @include('catalog-validation::review.validate-why', ['card' => $card, 'center' => true, 'suffix' => '-card'])
                @foreach ($card['reopenVetoes'] as $veto)
                    <p class="text-center text-xs text-neutral" data-testid="reopen-veto-{{ $review->catalog->value }}">{{ $veto }}</p>
                @endforeach
            </div>
        </article>
    @endforeach
</div>
