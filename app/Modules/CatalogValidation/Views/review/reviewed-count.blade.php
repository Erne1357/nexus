{{-- «N de M revisados» de una revisión (CV7). `$card` es de review.php. --}}
<p class="text-sm tabular-nums" data-testid="reviewed-count-{{ $card['review']->catalog->value }}">{{ $card['reviewed']['reviewed'] }} de {{ $card['reviewed']['total'] }} revisados</p>
