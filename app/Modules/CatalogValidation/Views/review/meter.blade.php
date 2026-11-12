{{--
    La barra de avance de una revisión (CV7, DESIGN.md «Revisión por registro»): cuántos activos van revisados. Siempre
    junto a su cifra escrita («28 de 40 revisados»), nunca sola; sin activos que revisar va llena. `$reviewed` es
    ['reviewed' => int, 'total' => int] y `$class`, su ancho.
--}}
@php($total = max(1, $reviewed['total']))
@php($value = $reviewed['total'] === 0 ? 1 : $reviewed['reviewed'])
<progress class="progress progress-success h-2 {{ $class ?? 'w-32' }}" value="{{ $value }}" max="{{ $total }}" aria-hidden="true"></progress>
