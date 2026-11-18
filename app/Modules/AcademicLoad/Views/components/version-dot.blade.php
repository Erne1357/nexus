{{--
    El punto de una versión publicada en una línea de versiones (DESIGN.md «Publicación» y «Versiones publicadas»): la
    versión en Mono dentro del círculo, con borde `success`, y la vigente rellena. Lo usan la línea del componente de
    publicar y la del tablero de versiones.

    <x-academic-load::version-dot :version="3" :current="true" />
--}}
@props(['version', 'current' => false])

<span {{ $attributes->class([
    'size-7 shrink-0 rounded-full grid place-items-center font-mono text-xs font-semibold border-2 border-success',
    'bg-success text-success-content' => $current,
    'bg-base-100 text-success' => ! $current,
]) }}>v{{ $version }}</span>
