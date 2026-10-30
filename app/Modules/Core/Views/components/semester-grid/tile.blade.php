{{--
    El mosaico de una materia en core::semester-grid.frame, el mismo en la ficha del alumno y en el editor del plan:
    un botón de alto fijo (104 px) y el ancho de su columna, que nada estira, en su renglón (`$row`, de SemesterGrid).
    Sus márgenes y su relleno están medidos para que la clave (ocho caracteres en Mono) y el icono quepan con nueve
    semestres a 1366 px: 4 px a cada lado de la columna (el mismo hueco que entre renglones) y 8 px de relleno.
    De arriba abajo: la clave en Mono con `$icon` a la derecha; el nombre en dos renglones a lo más, partido con
    guiones y completo en `title` (y en el texto, para lectores de pantalla); lo que va en el slot (texto `sr-only`);
    un renglón de requisitos (`$requisites`, recortado y completo en su `title`); y al pie, `$start` y `$end` en Mono.
    El relleno, el borde y el color de su estado llegan en `class`. `$chain` es su cadena de seriación: la franja a
    la izquierda (una sombra interior con el token chain-N, que no toca el borde). Con algo resaltado (`data-focus`
    en el marco), lo que no está en la cadena (`data-on-chain`) se atenúa, y el elegido (`data-me`) lleva anillo.
    `$muted` deja el nombre en el color del mosaico en lugar de `text-base-content`.
--}}
@props(['row', 'code', 'name', 'chain' => null, 'muted' => false])

@php
    // Un color por cadena, en clases completas: Tailwind solo publica los tokens chain-N que encuentra escritos.
    $chainColors = [
        '[--chain:var(--color-chain-1)]', '[--chain:var(--color-chain-2)]', '[--chain:var(--color-chain-3)]',
        '[--chain:var(--color-chain-4)]', '[--chain:var(--color-chain-5)]', '[--chain:var(--color-chain-6)]',
        '[--chain:var(--color-chain-7)]', '[--chain:var(--color-chain-8)]', '[--chain:var(--color-chain-9)]',
        '[--chain:var(--color-chain-10)]', '[--chain:var(--color-chain-11)]', '[--chain:var(--color-chain-12)]',
    ];
@endphp

<button type="button" {{ $attributes->class([
    'flex h-26 min-w-0 cursor-pointer flex-col overflow-hidden rounded-field border p-2 text-left transition-opacity motion-reduce:transition-none md:col-(--col) md:row-(--row) md:mx-1',
    'group-data-[focus]/grid:not-data-[on-chain]:opacity-20 group-data-[focus]/grid:not-data-[on-chain]:grayscale',
    'data-[me]:ring-2 data-[me]:ring-primary data-[me]:ring-offset-2 data-[me]:ring-offset-base-100',
    $chain === null ? '' : $chainColors[$chain % 12].' shadow-[inset_5px_0_0_var(--chain)] pl-3',
]) }} style="--row: {{ $row }}">
    <span class="-mr-0.5 flex h-4 shrink-0 items-center justify-between">
        <span class="truncate font-mono text-[12.5px] font-semibold leading-4 tracking-[-0.02em]">{{ $code }}</span>
        {{ $icon ?? '' }}
    </span>
    <span @class(['mt-1 line-clamp-2 hyphens-auto break-words text-[13px] leading-4', 'text-base-content' => ! $muted]) lang="es" title="{{ $name }}">{{ $name }}</span>
    {{ $slot }}
    <span class="mt-auto flex flex-col">
        @isset($requisites)
            <span {{ $requisites->attributes->class('truncate text-[11.5px] leading-4 text-neutral') }}>{{ $requisites }}</span>
        @endisset
        <span class="flex h-4 items-center justify-between gap-1.5 whitespace-nowrap font-mono text-xs leading-4">
            <span>{{ $start ?? '' }}</span>
            <span class="flex gap-1.5">{{ $end ?? '' }}</span>
        </span>
    </span>
</button>
