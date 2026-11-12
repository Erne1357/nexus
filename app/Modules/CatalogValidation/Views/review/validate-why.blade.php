{{--
    Por qué Validar catálogo está deshabilitado, escrito junto al botón (CV7; DESIGN.md «Revisión por registro»): un
    tooltip no lo ve quien usa teclado o pantalla táctil. El botón lo enlaza con `aria-describedby="why-<catálogo>"`.
    `$card` es de review.php; `$center` lo centra (tarjeta del tablero).
--}}
@php($why = $card['canValidate'] ? $card['validateReason'] : 'Solo el jefe del departamento valida.')
@if ($card['review']->status !== \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated && $why !== null)
    <p id="why-{{ $card['review']->catalog->value }}{{ $suffix ?? '' }}" @class(['flex items-center gap-1.5 text-xs text-neutral', 'justify-center' => $center ?? false]) data-testid="validate-reason{{ ($center ?? false) ? '-'.$card['review']->catalog->value : '' }}">
        <x-icon :name="$card['canValidate'] ? 'o-clock' : 'o-lock-closed'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $why }}
    </p>
@endif
