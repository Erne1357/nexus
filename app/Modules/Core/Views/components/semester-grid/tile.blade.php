{{--
    El mosaico de una materia en core::semester-grid.frame, el mismo en la ficha del alumno, en el editor del plan y
    en la propuesta de carrera:
    un botón de alto fijo (104 px) y el ancho de su columna, que nada estira, en su renglón (`$row`, de SemesterGrid).
    Sus márgenes y su relleno están medidos para que la clave (ocho caracteres en Mono) y el icono quepan con nueve
    semestres a 1366 px: 4 px a cada lado de la columna (el mismo hueco que entre renglones) y 8 px de relleno.
    De arriba abajo: la clave en Mono con `$icon` a la derecha; el nombre, partido con guiones y completo en `title`
    (y en el texto, para lectores de pantalla); lo que va en el slot (texto `sr-only`); un renglón de requisitos
    (`$requisites`, recortado y completo en su `title`); y al pie, `$start` y `$end` en Mono. El nombre ocupa dos
    renglones a lo más si hay requisitos y tres si no: el tercero toma el lugar del de requisitos y el mosaico mide
    lo mismo, así «Fundamentos de…» no se repite en tantas materias.
    El relleno, el borde y el color de su estado llegan en `class`. `$chain` es su cadena de seriación: la franja a
    la izquierda (una sombra interior con el token chain-N, que no toca el borde). Con algo resaltado (`data-focus`
    en el marco), lo que no está en la cadena (`data-on-chain`) se atenúa, y el elegido (`data-me`) lleva anillo.
    `$secondaryCode` es la clave del TecNM cuando `$code` es la del SII de la retícula (regla D8-bis): va junto a la clave,
    en Mono chico y tono neutro, en el mismo renglón para no quitarle alto al nombre ni al pie.
    `$muted` deja el nombre en el color del mosaico en lugar de `text-base-content`. Con `:interactive="false"` es solo
    lectura: un `div` sin cursor de mano (la retícula de «Por validar», donde se coloca desde la bandeja).
--}}
@props(['row', 'code', 'name', 'secondaryCode' => null, 'chain' => null, 'muted' => false, 'interactive' => true])

@php
    // Un color por cadena, en clases completas: Tailwind solo publica los tokens chain-N que encuentra escritos.
    $chainColors = [
        '[--chain:var(--color-chain-1)]', '[--chain:var(--color-chain-2)]', '[--chain:var(--color-chain-3)]',
        '[--chain:var(--color-chain-4)]', '[--chain:var(--color-chain-5)]', '[--chain:var(--color-chain-6)]',
        '[--chain:var(--color-chain-7)]', '[--chain:var(--color-chain-8)]', '[--chain:var(--color-chain-9)]',
        '[--chain:var(--color-chain-10)]', '[--chain:var(--color-chain-11)]', '[--chain:var(--color-chain-12)]',
    ];
@endphp

@php($tag = $interactive ? 'button' : 'div')
<{{ $tag }} @if ($interactive) type="button" @endif {{ $attributes->class([
    'flex h-26 min-w-0 flex-col overflow-hidden rounded-field border p-2 text-left transition-opacity motion-reduce:transition-none md:col-(--col) md:row-(--row) md:mx-1',
    'cursor-pointer' => $interactive,
    'group-data-[focus]/grid:not-data-[on-chain]:opacity-20 group-data-[focus]/grid:not-data-[on-chain]:grayscale',
    'data-[me]:ring-2 data-[me]:ring-primary data-[me]:ring-offset-2 data-[me]:ring-offset-base-100',
    $chain === null ? '' : $chainColors[$chain % 12].' shadow-[inset_5px_0_0_var(--chain)] pl-3',
]) }} style="--row: {{ $row }}">
    <span class="-mr-0.5 flex h-4 shrink-0 items-center justify-between">
        <span class="flex min-w-0 items-baseline gap-1.5">
            {{-- Con clave secundaria, la principal nunca se recorta: cede la del TecNM (D8-bis). --}}
            <span @class(['font-mono text-[12.5px] font-semibold leading-4 tracking-[-0.02em]', 'shrink-0' => $secondaryCode !== null, 'truncate' => $secondaryCode === null])>{{ $code }}</span>
            @if ($secondaryCode !== null)
                <span class="min-w-0 truncate font-mono text-[10px] leading-4 text-neutral" title="Clave del TecNM" data-secondary-code>{{ $secondaryCode }}</span>
            @endif
        </span>
        {{ $icon ?? '' }}
    </span>
    <span @class([
        'mt-1 hyphens-auto break-words text-[13px] leading-4',
        isset($requisites) ? 'line-clamp-2' : 'line-clamp-3',
        'text-base-content' => ! $muted,
    ]) lang="es" title="{{ $name }}">{{ $name }}</span>
    {{ $slot }}
    <span class="mt-auto flex flex-col">
        @isset($requisites)
            <span {{ $requisites->attributes->class('truncate text-[11.5px] leading-4 text-neutral') }}>{{ $requisites }}</span>
        @endisset
        <span class="flex h-4 items-center justify-between gap-1.5 whitespace-nowrap font-mono text-xs leading-4">
            {{-- El inicio (las cifras) nunca se recorta. Si el final no cabe, sus señales se caen de la última a la primera: van
                 en un solo renglón visible y las que no caben pasan a uno oculto, así que el orden del slot es su prioridad. --}}
            <span class="shrink-0">{{ $start ?? '' }}</span>
            <span class="flex h-4 min-w-0 flex-wrap justify-end gap-x-1.5 overflow-hidden">{{ $end ?? '' }}</span>
        </span>
    </span>
</{{ $tag }}>
