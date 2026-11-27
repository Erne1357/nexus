{{--
    El aviso de AZ-01 (core::self-approval): quien decide es quien solicitó lo que aprueba. No bloquea; recuerda que
    la bitácora lo registra como autoaprobación. Recibe `$title`.
--}}
<x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" :title="$title" description="Puedes continuar; la bitácora lo registrará como autoaprobación." data-testid="self-approval-warning" />
