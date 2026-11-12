{{--
    La celda de una revisión en la matriz: solo la insignia de estado, sin repetir el texto; cuándo se validó (o el día
    del último cambio, en revisión) va en el título. Una revisión que falta (`$review` nulo) es pendiente. Mientras no
    está validada, `$reviewed` dice junto a la insignia cuántos registros van revisados de cuántos (CV7), si tiene
    registros.
--}}
@php($status = $review?->status ?? \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending)
@php($when = match (true) {
    $review === null, $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending => null,
    $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => $review->validated_at,
    default => $review->updated_at,
})
@php($reviewedText = ($reviewed ?? null) !== null && $reviewed['total'] > 0 && $status !== \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated ? "{$reviewed['reviewed']} de {$reviewed['total']} revisados" : null)
@php($title = $status->label().($when ? ' · '.$when->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') : '').($reviewedText ? ' · '.$reviewedText : ''))
@php($icon = match ($status) { \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => 'o-check-circle', \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview => 'o-eye', default => 'o-clock' })
<span @class(['inline-flex flex-wrap items-center gap-x-2 gap-y-0.5' => ($showDate ?? false) || $reviewedText])>
    <x-core::status-badge :tone="$status->tone()" :label="$status->label()" :icon="$icon" class="text-xs [&_svg]:h-3 [&_svg]:w-3" :title="$title" />
    @if ($reviewedText)
        <span class="whitespace-nowrap text-xs text-neutral tabular-nums" data-testid="reviewed">{{ $reviewed['reviewed'] }}/{{ $reviewed['total'] }}<span class="sr-only"> revisados</span></span>
    @endif
    @if (($showDate ?? false) && $when)
        <span class="text-xs text-neutral tabular-nums">{{ $when->timezone(config('app.timezone'))->isToday() ? 'hoy' : $when->timezone(config('app.timezone'))->translatedFormat('j M') }}</span>
    @endif
</span>
