{{--
    Layout de la consulta pública de lo publicado (tarea 4.4): lo ven alumnos y docentes sin cuenta, así que no lleva el
    shell ni el menú. La banda es la del shell de DESIGN.md sin buscador ni menú (academic-load::components.published-band:
    la liebre que lleva al inicio de la consulta, «Nexus», la ruta «Horarios», los selectores de campus y periodo y el
    acceso) y su franja roja; el contenido va debajo, con el pie institucional. El tema es el del sistema (claro u
    oscuro) y no hay estado de menú. Las páginas lo piden con #[Layout('layouts::public')].
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-base-200 font-sans text-base-content antialiased">
        <livewire:academic-load::components.published-band :term-code="request()->route('periodo') ?? request()->query('periodo')" :campus-code="request()->route('campus') ?? request()->query('campus')" />

        <main id="nexus-content" class="mx-auto min-h-[calc(100vh_-_200px)] max-w-[calc(1680px_+_4rem)] px-8 pt-6.5 pb-6 max-desktop:px-6 max-desktop:pt-6 max-md:px-4 max-md:pt-[18px] max-md:pb-6">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-[calc(1680px_+_4rem)] border-t border-base-300 px-8 py-5 text-sm text-neutral max-desktop:px-6 max-md:px-4">
            Horarios oficiales del Instituto Tecnológico de Ciudad Juárez
        </footer>

        @livewireScripts
    </body>
</html>
