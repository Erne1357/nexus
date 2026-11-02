{{--
    La celda de una revisión en la matriz: solo la insignia de estado, sin repetir el texto; cuándo se validó (o el día
    del último cambio, en revisión) va en el título. Con `$campus` es la mini insignia de Salones de un campus: su
    código con el icono del estado, y el nombre del campus y el estado en `aria-label`. Una revisión que falta
    (`$review` nulo) es pendiente.
--}}
@php($status = $review?->status ?? \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending)
@php($when = match (true) {
    $review === null, $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending => null,
    $status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => $review->validated_at,
    default => $review->updated_at,
})
@php($title = $status->label().($when ? ' · '.$when->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') : ''))
@php($icon = match ($status) { \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => 'o-check-circle', \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview => 'o-eye', default => 'o-clock' })
@if ($roomCampus ?? null)
    <x-core::status-badge :tone="$status->tone()" :label="$roomCampus->code" :icon="$icon" class="text-xs [&_svg]:h-3 [&_svg]:w-3" :title="$roomCampus->name.': '.$title" data-testid="room-badge-{{ $roomCampus->code }}"><span class="sr-only">, {{ \Illuminate\Support\Str::lower($status->label()) }}</span></x-core::status-badge>
@else
    <span @class(['inline-flex flex-wrap items-center gap-x-2 gap-y-0.5' => $showDate ?? false])>
        <x-core::status-badge :tone="$status->tone()" :label="$status->label()" :icon="$icon" class="text-xs [&_svg]:h-3 [&_svg]:w-3" :title="$title" />
        @if (($showDate ?? false) && $when)
            <span class="text-xs text-neutral tabular-nums">{{ $when->timezone(config('app.timezone'))->isToday() ? 'hoy' : $when->timezone(config('app.timezone'))->translatedFormat('j M') }}</span>
        @endif
    </span>
@endif
