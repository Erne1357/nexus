{{--
    El mosaico de una materia en core::semester-grid.frame, el mismo en la ficha del alumno y en el editor del plan:
    un botón de alto fijo (104 px) y el ancho de su columna, que nada estira, en su renglón (`$row`, de SemesterGrid).
    De arriba abajo: la clave en Mono con `$icon` a la derecha; el nombre en dos renglones a lo más, partido con
    guiones y completo en `title` (y en el texto, para lectores de pantalla); lo que va en el slot (texto `sr-only`);
    un renglón de requisitos (`$requisites`, recortado y completo en su `title`); y al pie, `$start` y `$end` en Mono.
    El relleno, el borde y el color de su estado llegan en `class`. `$chain` es su cadena de seriación: la franja a
    la izquierda (una sombra interior con el token chain-N, que no toca el borde). Con algo resaltado (`data-focus`
    en el marco), lo que no está en la cadena (`data-on-chain`) se atenúa, y el elegido (`data-me`) lleva anillo.
    `$muted` deja el nombre en el color del mosaico en lugar de `text-base-content`.
--}}
@props(['row', 'code', 'name', 'chain' => null, 'muted' => false])

<button type="button" {{ $attributes->class([
    'flex h-26 min-w-0 cursor-pointer flex-col overflow-hidden rounded-field border px-2.5 py-2 text-left transition-opacity motion-reduce:transition-none md:col-(--col) md:row-(--row) md:mx-1.5',
    'group-data-[focus]/grid:not-data-[on-chain]:opacity-20 group-data-[focus]/grid:not-data-[on-chain]:grayscale',
    'data-[me]:ring-2 data-[me]:ring-primary data-[me]:ring-offset-2 data-[me]:ring-offset-base-100',
    'shadow-[inset_5px_0_0_var(--chain)] pl-3.5' => $chain !== null,
]) }} style="--row: {{ $row }}{{ $chain === null ? '' : '; --chain: var(--color-chain-'.($chain % 12 + 1).')' }}">
    <span class="flex h-4 shrink-0 items-center justify-between gap-1">
        <span class="truncate font-mono text-[12.5px] font-semibold leading-4">{{ $code }}</span>
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
