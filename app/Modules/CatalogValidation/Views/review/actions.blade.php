{{--
    «Validar catálogo» o «Reabrir» de una revisión (`$card`, de review.php). Validar abre el modal que dice qué valida; si
    falta revisar algún registro (CV7) o quien mira solo lee (Subdirección, admin; CV-S1), va deshabilitado y lo enlaza
    con `aria-describedby` a su motivo escrito (catalog-validation::review.validate-why), que pone quien incluye este
    parcial. Reabrir, si ya está validada; deshabilitado con su motivo escrito si la carga salió de borrador (CV5) o si
    quien mira no reabre. `$grow` (por omisión sí) reparte el ancho; `$small` los hace `btn-sm`; `$suffix` distingue el
    id del motivo cuando la misma revisión se pinta dos veces.
--}}
@php($catalog = $card['review']->catalog->value)
@php($grow ??= true)
@php($small ??= false)
@php($classes = ($grow ? 'flex-1 ' : '').($small ? 'btn-sm ' : ''))
@php($why = 'why-'.$catalog.($suffix ?? ''))
@if ($card['review']->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
    @if (! $card['canReopen'])
        <button type="button" class="btn btn-outline {{ $classes }}" aria-disabled="true" disabled title="Solo el jefe del departamento reabre." data-testid="reopen-{{ $catalog }}"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Reabrir<span class="sr-only">: solo el jefe del departamento reabre.</span></button>
    @elseif ($card['reopenVetoes'] === [])
        <x-button label="Reabrir" icon="o-arrow-uturn-left" class="btn-outline {{ $classes }}" wire:click="confirm('reopen', '{{ $catalog }}')" spinner="confirm" data-testid="reopen-{{ $catalog }}" />
    @else
        <button type="button" class="btn btn-outline {{ $classes }}" aria-disabled="true" disabled data-testid="reopen-{{ $catalog }}"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Reabrir</button>
    @endif
@elseif ($card['canValidate'] && $card['validateReason'] === null)
    <x-button label="Validar catálogo" icon="o-check" class="btn-primary {{ $classes }}" wire:click="confirm('validate', '{{ $catalog }}')" spinner="confirm" data-testid="validate-{{ $catalog }}" />
@else
    <button type="button" class="btn btn-primary {{ $classes }}" aria-disabled="true" disabled aria-describedby="{{ $why }}" data-testid="validate-{{ $catalog }}"><x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Validar catálogo</button>
@endif
