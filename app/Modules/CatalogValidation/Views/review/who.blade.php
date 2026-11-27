{{-- Quién y cuándo validó (DESIGN.md: la hora en Mono, zona America/Ciudad_Juarez). Los demás estados ya los dice la insignia. --}}
@if ($review->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
    <p class="text-sm text-neutral">Validó {{ $review->validator?->name ?? 'un usuario ya dado de baja' }} · <span class="tabular-nums">{{ $review->validated_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span></p>
@endif
