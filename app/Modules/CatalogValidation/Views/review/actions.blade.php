{{--
    Validar o Reabrir una revisión (`$card`, de review.php): Validar abre el modal que dice qué valida; Reabrir, si ya
    está validada, y deshabilitado con su motivo escrito si la carga salió de borrador (CV5). Quien solo lee no ve
    ninguno de los dos.
--}}
@php($catalog = $card['review']->catalog->value)
@if ($card['review']->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
    @if ($card['canReopen'])
        @if ($card['reopenVetoes'] === [])
            <x-button label="Reabrir" icon="o-arrow-uturn-left" class="btn-outline flex-1" wire:click="confirm('reopen', '{{ $catalog }}')" spinner="confirm" data-testid="reopen-{{ $catalog }}" />
        @else
            <button type="button" class="btn btn-outline flex-1" aria-disabled="true" disabled data-testid="reopen-{{ $catalog }}"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Reabrir</button>
        @endif
    @endif
@elseif ($card['canValidate'])
    <x-button label="Validar" icon="o-check" class="btn-primary flex-1" wire:click="confirm('validate', '{{ $catalog }}')" spinner="confirm" data-testid="validate-{{ $catalog }}" />
@endif
