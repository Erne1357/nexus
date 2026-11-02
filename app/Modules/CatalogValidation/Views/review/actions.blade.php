{{--
    Validar o Reabrir una revisión (`$card`, de review.php): Validar abre el modal que dice qué valida; Reabrir, si ya
    está validada, y deshabilitado con su motivo escrito si la carga salió de borrador (CV5). Quien solo lee
    (Subdirección, admin) los ve deshabilitados con su motivo en el tooltip y para lectores de pantalla (DESIGN.md,
    "Nada oculto"). `$grow` (por omisión sí) reparte el ancho entre los botones; `$small` los hace `btn-sm`.
--}}
@php($catalog = $card['review']->catalog->value)
@php($grow ??= true)
@php($small ??= false)
@php($classes = ($grow ? 'flex-1 ' : '').($small ? 'btn-sm ' : ''))
@if ($card['review']->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
    @if (! $card['canReopen'])
        <button type="button" class="btn btn-outline {{ $classes }}" aria-disabled="true" disabled title="Solo el jefe del departamento reabre." data-testid="reopen-{{ $catalog }}"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Reabrir<span class="sr-only">: solo el jefe del departamento reabre.</span></button>
    @elseif ($card['reopenVetoes'] === [])
        <x-button label="Reabrir" icon="o-arrow-uturn-left" class="btn-outline {{ $classes }}" wire:click="confirm('reopen', '{{ $catalog }}')" spinner="confirm" data-testid="reopen-{{ $catalog }}" />
    @else
        <button type="button" class="btn btn-outline {{ $classes }}" aria-disabled="true" disabled data-testid="reopen-{{ $catalog }}"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Reabrir</button>
    @endif
@elseif ($card['canValidate'])
    <x-button label="Validar" icon="o-check" class="btn-primary {{ $classes }}" wire:click="confirm('validate', '{{ $catalog }}')" spinner="confirm" data-testid="validate-{{ $catalog }}" />
@else
    <button type="button" class="btn btn-primary {{ $classes }}" aria-disabled="true" disabled title="Solo el jefe del departamento valida." data-testid="validate-{{ $catalog }}"><x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />Validar<span class="sr-only">: solo el jefe del departamento valida.</span></button>
@endif
