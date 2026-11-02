{{-- Quién y cuándo validó, o en qué va la revisión (DESIGN.md: la hora en Mono, zona America/Ciudad_Juarez). --}}
<p class="min-h-5 text-sm text-neutral">
    @if ($review->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
        Validó {{ $review->validator?->name ?? 'un usuario ya dado de baja' }} · <span class="tabular-nums">{{ $review->validated_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span>
    @elseif ($review->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview)
        En revisión
    @else
        Sin revisar
    @endif
</p>
