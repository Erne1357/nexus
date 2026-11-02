{{--
    La celda de una revisión en la matriz: la insignia de estado y, debajo (o al lado, con `$inline`), cuándo se
    validó; en revisión, el día del último cambio. Una revisión que falta (`$review` nulo) es pendiente.
--}}
@php($status = $review?->status ?? \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending)
@php($when = match (true) {
    $review === null, $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending => null,
    $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => $review->validated_at,
    default => $review->updated_at,
})
<div @class(['flex gap-x-2', 'flex-col gap-y-0.5' => ! ($inline ?? false), 'items-center' => $inline ?? false])>
    <span class="self-start"><x-core::status-badge :tone="$status->tone()" :label="$status->label()" :icon="match ($status) { \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => 'o-check-circle', \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview => 'o-eye', default => 'o-clock' }" /></span>
    @if ($when)
        <span class="text-xs text-neutral tabular-nums" title="{{ $when->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}">{{ $when->timezone(config('app.timezone'))->isToday() ? 'hoy' : $when->timezone(config('app.timezone'))->translatedFormat('j M') }}</span>
    @endif
</div>
