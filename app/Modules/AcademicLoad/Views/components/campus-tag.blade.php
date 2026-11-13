{{--
    La etiqueta de un campus en la propuesta de carrera y la bandeja: es la de Core (`x-core::campus-tag`, DESIGN.md
    «Propuesta de carrera por modalidad»), con los mismos `campus` y `size`.
--}}
@props(['campus', 'size' => 'sm'])

<x-core::campus-tag :campus="$campus" :size="$size" {{ $attributes }} />
